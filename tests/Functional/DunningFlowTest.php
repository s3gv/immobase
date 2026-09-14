<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Dunning\Domain\BaseRate;
use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Property\Domain\Holding;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\UnitOwner;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Der Weg vom Hinweis zum Schreiben, so wie ihn jemand durchklickt.
 *
 * Die Uebersicht zeigt, was ueberfaellig ist und noch niemanden beschaeftigt
 * hat. Ein Klick macht daraus einen Vorgang, drei Schritte machen daraus ein
 * Schreiben, und danach steht es fest.
 */
final class DunningFlowTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
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

    /** Von der Tafel der Ueberfaelligen bis zum ausgestellten Schreiben. */
    public function testTheWholeWayFromTheHintToTheLetter(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $overview = $client->request('GET', '/finanzen/mahnwesen');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Hausgeld 12/2025', $overview->text());
        self::assertStringContainsString('überfällig', $overview->text());

        $notice = self::dunned($client, $overview);
        self::assertSame('MA-99001-1', $notice->reference());
        self::assertCount(1, $notice->lines());

        // Der zweite Schritt nimmt die Frist an, der dritte stellt aus.
        $client->request('GET', self::stepUrl($notice, 'schreiben'));
        self::assertResponseIsSuccessful();

        $issue = $client->request('GET', self::stepUrl($notice, 'ausstellung'));
        self::assertCount(0, $issue->filter('.ib-note--warning'), 'Nichts fehlt');
        $client->submitForm('Ausstellen');
        self::assertResponseRedirects('/finanzen/mahnwesen/schreiben/'.$notice->id());

        self::assertFalse(self::reloaded($notice)->isDraft());

        // Und die Liste zeigt den Vorgang mit laufender Frist: ein Zustand,
        // den keine Seite zeichnen kann, ist keiner.
        $list = $client->request('GET', '/finanzen/mahnwesen');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Frist läuft', $list->filter('tbody')->text());
    }

    /**
     * Solange die Frist laeuft, ist keine Mahnung faellig.
     *
     * Sonst bekaeme der Schuldner zwei Schreiben in derselben Woche, und das
     * zweite entwertete die Frist des ersten.
     */
    public function testNothingIsDueWhileTheDeadlineRuns(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $overview = $client->request('GET', '/finanzen/mahnwesen');
        $notice = self::dunned($client, $overview);
        $client->request('GET', self::stepUrl($notice, 'ausstellung'));
        $client->submitForm('Ausstellen');

        $due = $client->request('GET', '/finanzen/mahnwesen?zustand=due');

        self::assertSame(0, $due->filter('tbody tr')->count());
    }

    /** Die Detailseite zeigt Verlauf, Zinsen und das Schreiben. */
    public function testTheDetailPageShowsTheWholeCase(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $overview = $client->request('GET', '/finanzen/mahnwesen');
        self::dunned($client, $overview);
        $claim = self::theOnlyClaim();

        $page = $client->request('GET', '/finanzen/mahnwesen/'.$claim->id());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Hausgeld 12/2025', $page->text());
        self::assertStringContainsString('300,00', $page->text());
        self::assertStringContainsString('Zinsstaffel', $page->text());
        // Der Satz steht zerlegt da: Basiszins, Zuschlag, Summe. Wer eine
        // Zinsforderung bestreitet, bestreitet einen dieser Werte.
        self::assertStringContainsString('1,27 %', $page->text(), 'Der Basiszinssatz');
        self::assertStringContainsString('5,00 %', $page->text(), 'Die fuenf Punkte des § 288 Abs. 1');
        self::assertStringContainsString('6,27 %', $page->text(), 'Und was daraus folgt');
    }

    /** Erledigt melden beendet den Vorgang an dem Tag, den jemand eintraegt. */
    public function testSettlingEndsTheCaseOnTheDayGiven(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $overview = $client->request('GET', '/finanzen/mahnwesen');
        self::dunned($client, $overview);
        $claim = self::theOnlyClaim();

        $page = $client->request('GET', '/finanzen/mahnwesen/'.$claim->id());
        $client->request('POST', '/finanzen/mahnwesen/'.$claim->id().'/erledigt', [
            '_token' => self::tokenOn($page),
            'settledOn' => '2026-03-15',
        ]);

        self::assertSame('2026-03-15', self::reloadedClaim($claim)->arrears()->settledOn()?->format('Y-m-d'));
    }

    /** Die Basiszinssaetze lassen sich pflegen — und die Seite sagt, wenn einer fehlt. */
    public function testTheBaseRatesCanBeMaintained(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();

        $page = $client->request('GET', '/finanzen/mahnwesen/basiszinssaetze');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1,52', $page->text());
        self::assertCount(0, $page->filter('.ib-note--warning'), 'Das laufende Halbjahr ist eingetragen');

        $client->request('POST', '/finanzen/mahnwesen/basiszinssaetze/eintragen', [
            '_token' => self::tokenOn($page),
            'validFrom' => '2027-01-01',
            'rate' => '-0,75',
        ]);

        $after = $client->request('GET', '/finanzen/mahnwesen/basiszinssaetze');
        self::assertStringContainsString('-0,75', $after->text(), 'Auch das Vorzeichen');
    }

    /** Fehlt der Satz fuers laufende Halbjahr, steht es auf der Seite. */
    public function testAMissingBaseRateIsSaidOnThePage(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW]);
        self::theBaseRates(until: 2020);
        self::buildTheProperty();

        $page = $client->request('GET', '/finanzen/mahnwesen/basiszinssaetze');

        self::assertCount(1, $page->filter('.ib-note--warning'));
        self::assertStringContainsString('laufende Halbjahr', $page->filter('.ib-note--warning')->text());
    }

    /** Eine Forderung von Hand — der Weg fuer alles, was wir nicht verfolgen. */
    public function testAClaimCanBeRecordedByHand(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();

        $form = $client->request('GET', '/finanzen/mahnwesen/erfassen');
        self::assertResponseIsSuccessful();

        $token = self::tokenOn($form);

        $client->request('POST', '/finanzen/mahnwesen/erfassen', [
            '_token' => $token,
            'unitId' => self::firstUnitId(),
            'creditor' => 'community',
            'subject' => 'Sonderumlage Dach',
            'amount' => '1.250,00',
            'dueOn' => '2026-04-01',
            'defaultFrom' => '2026-04-02',
        ]);

        $claims = self::claims()->allOpen();
        self::assertCount(1, $claims, 'Der erste Schritt legt an');
        self::assertSame(125000, $claims[0]->open()->cents());
        self::assertSame('2026-04-02', $claims[0]->arrears()->beginsOn()->format('Y-m-d'));

        // Der erste Schritt fuehrt auf den zweiten, und dort kommt der
        // naechste Posten dazu — ohne Einheit und ohne Glaeubiger, denn
        // beides steht fest.
        self::assertResponseRedirects('/finanzen/mahnwesen/erfassen/'.$claims[0]->id().'/weitere');
        $client->request('POST', '/finanzen/mahnwesen/erfassen/'.$claims[0]->id().'/weitere', [
            '_token' => $token,
            'direction' => 'add',
            'subject' => 'Sonderumlage Dach, 2. Rate',
            'amount' => '750,00',
            'dueOn' => '2026-05-01',
            'defaultFrom' => '2026-05-02',
        ]);

        $both = self::claims()->allOpen();
        self::assertCount(2, $both, 'Derselbe Schuldner, ein weiterer Posten');
        self::assertSame(
            [$both[0]->debtor()->partyId()],
            array_unique(array_map(static fn ($claim): string => $claim->debtor()->partyId(), $both)),
        );

        // „Weiter" ohne Betrag traegt nichts nach und fuehrt ins Pruefen.
        $review = $client->request('POST', '/finanzen/mahnwesen/erfassen/'.$claims[0]->id().'/weitere', [
            '_token' => $token,
            'direction' => 'forward',
        ]);
        self::assertResponseRedirects('/finanzen/mahnwesen/erfassen/'.$claims[0]->id().'/pruefen');
        self::assertCount(2, self::claims()->allOpen(), 'Eine leere Zeile ist keine Forderung');

        $review = $client->followRedirect();
        self::assertStringContainsString('Sonderumlage Dach, 2. Rate', $review->text());
        self::assertCount(1, $review->filter('form[action$="/mahnen"]'), 'Von hier geht es ins Mahnen');
    }

    /**
     * Ein nachgetragener Posten bekommt die Beteiligten seines eigenen Tages.
     *
     * Maerz- und Aprilmiete in einem Ablauf, und die Einheit wechselt zum
     * 1. April den Eigentuemer: die Aprilforderung gehoert dem neuen. Beide
     * in ein Schreiben zu nehmen, hiesse dem einen das Geld des anderen
     * mitzufordern — sie werden darum zwei Vorgaenge, und die Anwendung sagt
     * das, damit niemand den zweiten Posten vergeblich in der Liste sucht.
     */
    public function testAnAddedItemBelongsToThePartiesOfItsOwnDay(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW, DunningPermissions::EDIT]);
        self::theBaseRates();
        self::buildTheProperty();
        $buyer = self::theSecondUnitChangedHandsOn('2026-04-01');

        $form = $client->request('GET', '/finanzen/mahnwesen/erfassen');
        $token = self::tokenOn($form);

        $client->request('POST', '/finanzen/mahnwesen/erfassen', [
            '_token' => $token,
            'unitId' => self::unitIds()[1] ?? '',
            'creditor' => 'community',
            'subject' => 'Hausgeld 03/2026',
            'amount' => '300,00',
            'dueOn' => '2026-03-03',
        ]);

        $first = self::claims()->allOpen()[0] ?? null;
        self::assertInstanceOf(Claim::class, $first);
        self::assertNotSame($buyer->id(), $first->debtor()->partyId(), 'Der Maerz gehoert dem Verkaeufer');

        $client->request('POST', '/finanzen/mahnwesen/erfassen/'.$first->id().'/weitere', [
            '_token' => $token,
            'direction' => 'add',
            'subject' => 'Hausgeld 04/2026',
            'amount' => '300,00',
            'dueOn' => '2026-04-03',
        ]);

        $both = self::claims()->allOpen();
        self::assertCount(2, $both);
        $added = $both[0]->id() === $first->id() ? $both[1] : $both[0];
        self::assertSame($buyer->id(), $added->debtor()->partyId(), 'Der April gehoert dem Kaeufer');

        // Zwei Schuldner, zwei Buendel — und ein Hinweis darauf.
        self::assertCount(1, self::openFor($first), 'Der zweite Posten haengt nicht am ersten');
        $page = $client->followRedirect();
        self::assertStringContainsString('eigener Vorgang', $page->text());
    }

    /** Ohne Bearbeitungsrecht gibt es weder Ablauf noch Erfassung. */
    public function testTheFlowNeedsTheEditPermission(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW]);
        self::theBaseRates();
        self::buildTheProperty();

        $client->request('GET', '/finanzen/mahnwesen/erfassen');

        self::assertResponseStatusCodeSame(403);
    }

    /** Wer nur lesen darf, sieht die Liste — und keinen Knopf. */
    public function testAReaderSeesTheListWithoutButtons(): void
    {
        $client = self::signedInWith([DunningPermissions::VIEW]);
        self::theBaseRates();
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $page = $client->request('GET', '/finanzen/mahnwesen');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Hausgeld 12/2025', $page->text());
        self::assertCount(0, $page->filter('form[action*="/mahnen/"]'), 'Kein Mahnen-Knopf');
        self::assertCount(0, $page->filter('a[href$="/erfassen"]'), 'Und kein Erfassen');
    }

    protected static function testEmail(): string
    {
        return 'mahnklick@example.org';
    }

    /** Die zweite Einheit wechselt an diesem Tag den Eigentuemer. */
    private static function theSecondUnitChangedHandsOn(string $day): Party
    {
        $buyer = self::aBuyer();
        $property = self::properties()->byId(self::propertyId());
        self::assertNotNull($property);
        $changesOn = new DateTimeImmutable($day);

        foreach ($property->units() as $unit) {
            if ($unit->id() !== (self::unitIds()[1] ?? null)) {
                continue;
            }

            foreach ($unit->owners() as $owner) {
                $unit->removeOwner($owner);
            }

            $mea = Mea::of('200.00', 1000);
            new UnitOwner($unit, self::anOwner()->id(), $mea, Holding::of(null, $changesOn->modify('-1 day')));
            new UnitOwner($unit, $buyer->id(), $mea, Holding::of($changesOn, null));
        }

        self::properties()->save($property);

        return $buyer;
    }

    private static function aBuyer(): Party
    {
        foreach (self::parties()->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if (99008 === $known->reference()) {
                return $known;
            }
        }

        $buyer = new Party(
            99008,
            PartyKind::Person,
            'Kaufmann',
            'Karla',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Kaufweg 2', '40233', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('karla@example.org')]),
        );
        self::parties()->save($buyer);

        return $buyer;
    }

    /** @return list<Claim> */
    private static function openFor(Claim $claim): array
    {
        return self::claims()->openFor($claim->debtor()->partyId(), $claim->source()->creditorIdentity());
    }

    /** Auf „Mahnen" klicken — und den Entwurf zurueckbekommen. */
    private static function dunned(KernelBrowser $client, Crawler $overview): Notice
    {
        $form = $overview->filter('form[action*="/mahnen/"]')->first();
        self::assertGreaterThan(0, $form->count(), 'Die Tafel bietet das Mahnen an');
        $client->request('POST', (string) $form->attr('action'), ['_token' => self::tokenOn($overview)]);

        $notices = self::getContainer()->get(NoticeRepository::class);
        self::assertInstanceOf(NoticeRepository::class, $notices);
        $claim = self::claims()->allOpen()[0] ?? null;
        self::assertInstanceOf(Claim::class, $claim);
        $draft = $notices->openDraftFor($claim->debtor()->partyId(), $claim->source()->creditorIdentity());
        self::assertInstanceOf(Notice::class, $draft);

        return $draft;
    }

    private static function theOnlyClaim(): Claim
    {
        $claim = self::claims()->allOpen()[0] ?? null;
        self::assertInstanceOf(Claim::class, $claim);

        return $claim;
    }

    private static function firstUnitId(): string
    {
        $unitId = self::unitIds()[0] ?? null;
        self::assertIsString($unitId);

        return $unitId;
    }

    private static function stepUrl(Notice $notice, string $step): string
    {
        return '/finanzen/mahnwesen/schreiben/'.$notice->id().'/'.$step;
    }

    private static function reloaded(Notice $notice): Notice
    {
        $notices = self::getContainer()->get(NoticeRepository::class);
        self::assertInstanceOf(NoticeRepository::class, $notices);
        $found = $notices->byId($notice->id());
        self::assertInstanceOf(Notice::class, $found);

        return $found;
    }

    private static function reloadedClaim(Claim $claim): Claim
    {
        $found = self::claims()->byId($claim->id());
        self::assertInstanceOf(Claim::class, $found);

        return $found;
    }

    private static function claims(): ClaimRepository
    {
        $claims = self::getContainer()->get(ClaimRepository::class);
        self::assertInstanceOf(ClaimRepository::class, $claims);

        return $claims;
    }

    /** Die Reihe der Bundesbank — bis zu einem Jahr, wenn eines genannt ist. */
    private static function theBaseRates(int $until = 9999): void
    {
        $rates = self::getContainer()->get(BaseRateRepository::class);
        self::assertInstanceOf(BaseRateRepository::class, $rates);

        foreach ($rates->all()->all() as $rate) {
            $rates->remove($rate);
        }

        foreach (self::BASE_RATES as $day => $bps) {
            if ((int) substr($day, 0, 4) <= $until) {
                $rates->save(new BaseRate(new DateTimeImmutable($day), $bps));
            }
        }
    }

    private static function tokenOn(Crawler $crawler): string
    {
        $token = $crawler->filter('main input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }
}
