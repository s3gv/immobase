<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Dunning\Application\ComposeNotice;
use App\Module\Dunning\Application\IssueNotice;
use App\Module\Dunning\Application\StartClaim;
use App\Module\Dunning\Application\SurveyOverdue;
use App\Module\Dunning\Domain\BaseRate;
use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Module\Dunning\Domain\Notice;
use App\Module\Property\Domain\BankAccount;
use App\Shared\Bank\Bic;
use App\Shared\Bank\Iban;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die beiden Blaetter des Mahnwesens.
 *
 * **Das Mahnschreiben** geht an einen Menschen: es braucht eine Anrede, den
 * Glaeubiger in dessen Namen gefordert wird, die Forderungen einzeln und ein
 * Konto. **Die Zusammenfassung** geht an niemanden — sie ist das, was man dem
 * Amtsgericht mitgibt, und traegt darum die Felder des Mahnbescheidsantrags.
 */
final class DunningPdfTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use ReadsPdfArchives;
    use SignsIn;

    private const array BASE_RATES = [
        '2016-01-01' => -83, '2016-07-01' => -88, '2023-01-01' => 162, '2023-07-01' => 312,
        '2024-01-01' => 362, '2024-07-01' => 337, '2025-01-01' => 227, '2025-07-01' => 127,
        '2026-07-01' => 152,
    ];

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Das Mahnschreiben traegt, was ein Brief an einen Menschen braucht.
     *
     * Die Anrede steht mit dabei: ohne sie beginnt der Text mitten im Satz.
     * Und sie ist neutral — aus einem Namen folgt keine Anrede, und eine
     * falsche steht auf einem Schreiben, das jemand aufhebt.
     */
    public function testTheLetterCarriesEverythingALetterNeeds(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        $text = self::letterOf($client, self::anIssuedNotice());

        self::assertStringContainsString('Zahlungserinnerung', $text);
        self::assertStringContainsString('Sehr geehrte Damen und Herren', $text);
        self::assertStringContainsString('Paula Prüfer', $text, 'Der Empfänger');
        self::assertStringContainsString('Im Namen und Auftrag von', $text);
        self::assertStringContainsString('Abrechnungsobjekt Prüfweg', $text, 'Der Gläubiger');
        self::assertStringContainsString('Hausgeld 12/2025', $text);
        self::assertStringContainsString('300,00', $text);
        self::assertStringContainsString('DE02 1203 0000 0000 2020 51', $text, 'Das Konto');
        self::assertStringContainsString('§ 288 Abs. 1 BGB', $text, 'Woher die Zinsen kommen');

        // Im Anschriftfeld steht jede Angabe auf ihrer Zeile. „Kontrollweg 9,
        // 40233 Düsseldorf" ist die Form fuer eine Liste; im Fensterkuvert
        // gehoert sie getrennt, sonst liest sie der Zusteller als eine Zeile.
        self::assertStringContainsString('Kontrollweg 9', $text, 'Die Straße');
        self::assertStringContainsString('40233 Düsseldorf', $text, 'Postleitzahl und Ort');
        self::assertStringNotContainsString('Kontrollweg 9, 40233', $text, 'Beides in einer Zeile');

        // Wer zwei Seiten aus dem Kuvert nimmt, soll sehen, ob eine dritte fehlt.
        self::assertStringContainsString('Seite 1 von 1', $text, 'Die Fußzeile');
    }

    /**
     * Nach einem Bankwechsel steht auf dem Beleg weiter das alte Konto.
     *
     * Das Blatt ist zugestellt; es traegt die Zahlungsanweisung, die
     * hinausging. Laese es das Konto von heute, zeigte derselbe Beleg eine
     * Anweisung, die es nie gab — und wer auf das alte Konto ueberwies,
     * haette danach an die falsche Stelle gezahlt.
     */
    public function testTheLetterKeepsTheAccountAfterTheBankChanges(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        $notice = self::anIssuedNotice();

        $property = self::properties()->byId(self::propertyId());
        self::assertNotNull($property);
        $property->accountsAs($property->accounting()->collectedVia(BankAccount::of(
            Iban::fromString('DE68210501700012345678'),
            Bic::fromString('BYLADEM1001'),
            'WEG Prüfweg 3',
            'Neues Hausgeldkonto',
        )));
        self::properties()->save($property);

        $text = self::letterOf($client, $notice);

        self::assertStringContainsString('DE02 1203 0000 0000 2020 51', $text, 'Das Konto von damals');
        self::assertStringNotContainsString('DE68', $text, 'Und nicht das von heute');
    }

    /** Bei der letzten Mahnung steht der Hinweis aufs Gericht — vorher nicht. */
    public function testOnlyTheFinalReminderMentionsTheCourt(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        $reminder = self::letterOf($client, self::anIssuedNotice());

        self::assertStringNotContainsString('gerichtliche Mahnverfahren', $reminder);
    }

    /** Zweimal geholt — Byte fuer Byte dasselbe. */
    public function testTheSameNoticeProducesTheSameBytes(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        $notice = self::anIssuedNotice();

        $client->request('GET', '/finanzen/mahnwesen/schreiben/'.$notice->id().'/pdf');
        $first = (string) $client->getResponse()->getContent();
        $client->request('GET', '/finanzen/mahnwesen/schreiben/'.$notice->id().'/pdf');

        self::assertSame($first, (string) $client->getResponse()->getContent());
    }

    /** Aus einem Entwurf entsteht kein Blatt. */
    public function testADraftHasNoLetter(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $draft = self::aDraft();

        $client->request('GET', '/finanzen/mahnwesen/schreiben/'.$draft->id().'/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Die Zusammenfassung traegt die Felder des Mahnbescheidsantrags.
     *
     * Glaeubiger und Schuldner mit Anschrift, je Hauptforderung Betrag,
     * Faelligkeit und Verzugsbeginn, die Zinsstaffel **je Forderung**, die
     * Nebenforderungen einzeln und die Mahnhistorie. Eine Zinsstaffel, die
     * nicht sagt, worauf sie laeuft, laesst sich keiner Forderung zuordnen.
     */
    public function testTheCourtSummaryCarriesWhatTheApplicationAsksFor(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        $notice = self::anIssuedNotice();
        $claim = self::claims()->allOpen()[0] ?? null;
        self::assertInstanceOf(Claim::class, $claim);

        $client->request('GET', '/finanzen/mahnwesen/'.$claim->id().'/mahnverfahren');
        self::assertResponseIsSuccessful();
        $text = self::readable((string) $client->getResponse()->getContent());

        // Beteiligte mit Anschrift — und nicht „Einheit" als Beschriftung.
        // Jede der beiden Anschriften sagt, wem sie gehoert: auf einem
        // Blatt fuers Amtsgericht darf nicht zweimal dasselbe Wort ueber
        // zwei verschiedenen Adressen stehen.
        self::assertStringContainsString('Gläubiger', $text);
        self::assertStringContainsString('Anschrift des Gläubigers', $text);
        self::assertStringContainsString('Anschrift des Schuldners', $text);
        self::assertStringContainsString('Kontrollweg 9', $text);
        self::assertStringNotContainsString('Einheit', $text);

        // Hauptforderung mit Faelligkeit und Verzugsbeginn.
        self::assertStringContainsString('Hauptforderungen', $text);
        self::assertStringContainsString('01.12.2025', $text);
        self::assertStringContainsString('02.12.2025', $text);

        // Die Staffel, dem Betreff zugeordnet: der Betreff steht zweimal da
        // — einmal als Hauptforderung und einmal ueber ihren Zinsen. Eine
        // Staffel, die nicht sagt, worauf sie laeuft, laesst sich im Antrag
        // keiner Forderung zuordnen und ist dort wertlos.
        self::assertStringContainsString('Zinsstaffel', $text);
        self::assertStringContainsString('Tage zu', $text);
        self::assertSame(2, substr_count($text, 'Hausgeld 12/2025'));

        // Nebenforderungen einzeln — wer sie in die Hauptforderung rechnete,
        // verzinste sie mit.
        self::assertStringContainsString('Nebenforderungen', $text);
        self::assertStringContainsString('Summe', $text);

        // Und die Historie mit Datum je Stufe.
        self::assertStringContainsString('Mahnhistorie', $text);
        self::assertStringContainsString($notice->reference(), $text);
    }

    /** Ohne Leserecht gibt es keines der beiden Blaetter. */
    public function testTheSheetsNeedTheViewPermission(): void
    {
        $client = self::signedInWith([]);
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $client->request('GET', '/finanzen/mahnwesen/'.self::anyId().'/mahnverfahren');

        self::assertResponseStatusCodeSame(403);
    }

    protected static function testEmail(): string
    {
        return 'mahnbrief@example.org';
    }

    private static function letterOf(KernelBrowser $client, Notice $notice): string
    {
        $client->request('GET', '/finanzen/mahnwesen/schreiben/'.$notice->id().'/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));

        return self::readable((string) $client->getResponse()->getContent());
    }

    private static function anIssuedNotice(): Notice
    {
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $notice = self::aDraft();

        $issue = self::getContainer()->get(IssueNotice::class);
        self::assertInstanceOf(IssueNotice::class, $issue);
        $issue->issue($notice, new DateTimeImmutable('today'));

        return $notice;
    }

    private static function aDraft(): Notice
    {
        $overdue = self::getContainer()->get(SurveyOverdue::class);
        self::assertInstanceOf(SurveyOverdue::class, $overdue);
        $start = self::getContainer()->get(StartClaim::class);
        self::assertInstanceOf(StartClaim::class, $start);
        $compose = self::getContainer()->get(ComposeNotice::class);
        self::assertInstanceOf(ComposeNotice::class, $compose);

        $today = new DateTimeImmutable('today');
        $items = $overdue->on($today);
        self::assertNotEmpty($items);
        $claim = $start->fromTheOverdue($items[0]);

        return $compose->draftFor(
            $claim->debtor()->partyId(),
            $claim->source()->creditorIdentity(),
            [$claim],
            $today,
        );
    }

    private static function anyId(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }

    private static function claims(): ClaimRepository
    {
        $claims = self::getContainer()->get(ClaimRepository::class);
        self::assertInstanceOf(ClaimRepository::class, $claims);

        return $claims;
    }

    private static function theBaseRates(): void
    {
        $rates = self::getContainer()->get(BaseRateRepository::class);
        self::assertInstanceOf(BaseRateRepository::class, $rates);

        foreach ($rates->all()->all() as $rate) {
            $rates->remove($rate);
        }

        foreach (self::BASE_RATES as $day => $bps) {
            $rates->save(new BaseRate(new DateTimeImmutable($day), $bps));
        }
    }
}
