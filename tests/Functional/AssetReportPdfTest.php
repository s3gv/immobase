<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ReleaseAssetReport;
use App\Module\Billing\Application\StartAssetReport;
use App\Module\Billing\Domain\AssetItem;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Finance\Application\SaveLoan;
use App\Module\Finance\Domain\LoanTerms;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Vermoegensbericht als Brief.
 *
 * Was daraufsteht, verlangt § 28 Abs. 4 WEG: der Stand der
 * Erhaltungsruecklage und eine Aufstellung des wesentlichen
 * Gemeinschaftsvermoegens.
 *
 * Und was **nicht** daraufsteht, ist genauso wichtig: kein Beschluss. Der
 * Bericht wird zur Kenntnis genommen; wer eine Angabe fuer falsch haelt, kann
 * Berichtigung verlangen.
 */
final class AssetReportPdfTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use ReadsPdfArchives;
    use SignsIn;

    private const int REPORT_YEAR = 2025;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Je Einheit ein Schreiben, alle in einem Archiv. */
    public function testEveryUnitGetsItsOwnLetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $report = self::anIssuedReport();

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(2, self::filesIn((string) $client->getResponse()->getContent()));
    }

    /** Auf dem Blatt stehen beide Pflichtbausteine — und der Hinweis, dass nichts beschlossen wird. */
    public function testTheLetterCarriesBothPartsAndTheNote(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $report = self::anIssuedReport();

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');
        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('Vermögensbericht 2025', $text);
        self::assertStringContainsString('Stichtag 31.12.2025', $text);
        self::assertStringContainsString('Stand der Erhaltungsrücklage', $text);
        self::assertStringContainsString('Anfangsbestand', $text);
        self::assertStringContainsString('Girokonto', $text);
        self::assertStringContainsString('Gemeinschaftsvermögen', $text);
        self::assertStringContainsString('zur Kenntnis genommen', $text);
    }

    /**
     * Die offenen Hausgelder stehen mit Nummer und ohne Namen darauf.
     *
     * Jeder Eigentuemer traegt das Ausfallrisiko mit und darf wissen, wie
     * hoch es ist. Der Bericht stellt ihn aber nicht an den Pranger.
     */
    public function testClaimsCarryTheUnitNumberAndNotTheName(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), self::REPORT_YEAR);
        $report = self::anIssuedReport(build: false);

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');
        $text = self::textIn((string) $client->getResponse()->getContent());
        $claims = self::between($text, 'Forderungen gegen Eigentümer', 'Weiteres Vermögen');

        self::assertStringContainsString('Einheit 1', $claims);
        self::assertStringContainsString('300,00', $claims);
        self::assertStringNotContainsString('Prüfer', $claims, 'Kein Name in der Forderungszeile');
        // Im Anschriftfeld steht er sehr wohl — dorthin geht der Brief ja.
        self::assertStringContainsString('Prüfer', $text);
    }

    /**
     * Dasselbe Archiv, zweimal geholt — Byte fuer Byte dasselbe.
     *
     * Ein Schreiben ist ein Beleg. Zwei Ausdrucke desselben Berichts, die sich
     * unterscheiden, waeren zwei Belege.
     */
    public function testTheSameReportProducesTheSameBytes(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $report = self::anIssuedReport();

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');
        $first = (string) $client->getResponse()->getContent();

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');

        self::assertSame($first, (string) $client->getResponse()->getContent());
    }

    /**
     * Eine spaetere Buchung aendert das zugestellte Blatt nicht.
     *
     * Auch nicht, wenn sie auf einen Tag vor dem Stichtag gebucht wird: was
     * herausgegeben ist, ist herausgegeben.
     */
    public function testABookingAfterTheIssueDoesNotChangeTheLetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $report = self::anIssuedReport();

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');
        $before = (string) $client->getResponse()->getContent();

        self::alsoMovedTheReserve(ReserveMovementKind::Withdrawal, Money::fromCents(250000), '2025-05-05');

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');

        self::assertSame($before, (string) $client->getResponse()->getContent());
    }

    /** Aus einem Entwurf entsteht kein Bericht. */
    public function testADraftHasNoPdf(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();
        $report = self::aReport();

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Das Darlehen steht mit seiner Restschuld auf dem Blatt.
     *
     * Gerechnet und nicht getippt: es ist die einzige Zahl der Aufstellung,
     * die niemand eingetragen hat, und genau darum muss sie dastehen.
     */
    public function testTheLetterCarriesTheOutstandingLoan(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();
        self::alsoOwesALoan();
        $report = self::anIssuedReport(build: false);

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id().'/pdf');
        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('Darlehen', $text);
        self::assertStringContainsString('Heizung · Prüfbank', $text);
    }

    protected static function testEmail(): string
    {
        return 'berichtpdf@example.org';
    }

    /** Der Abschnitt zwischen zwei Ueberschriften — der Brief ist eine Zeile Text. */
    private static function between(string $text, string $from, string $to): string
    {
        $start = mb_strpos($text, $from);
        self::assertIsInt($start, 'Der Abschnitt „'.$from.'" fehlt');
        $end = mb_strpos($text, $to, $start);
        self::assertIsInt($end, 'Der Abschnitt „'.$to.'" fehlt');

        return mb_substr($text, $start, $end - $start);
    }

    private static function alsoOwesALoan(): void
    {
        $save = self::getContainer()->get(SaveLoan::class);
        self::assertInstanceOf(SaveLoan::class, $save);
        $loan = $save->forProperty(self::propertyId(), LoanTerms::withPayment(
            Money::fromCents(8000000),
            240,
            new DateTimeImmutable('2023-07-01'),
            Money::fromCents(70000),
        ));
        $save->describe($loan, 'Heizung', 'Prüfbank', '');
    }

    private static function anIssuedReport(bool $build = true): AssetReport
    {
        if ($build) {
            self::buildTheProperty();
        }

        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(400000), '2024-01-01');
        self::alsoMovedTheReserve(ReserveMovementKind::Contribution, Money::fromCents(120000), '2025-06-30');

        $report = self::aReport();
        $item = $report->items()[0] ?? null;
        self::assertInstanceOf(AssetItem::class, $item);
        $item->describe('Girokonto', Money::fromCents(520000), true, '');

        $furniture = new AssetItem($report, 2, AssetKind::Holding);
        $furniture->describe('Gartengeräte', null, false, '');
        self::reports()->save($report);

        $release = self::getContainer()->get(ReleaseAssetReport::class);
        self::assertInstanceOf(ReleaseAssetReport::class, $release);
        $release->release($report, new DateTimeImmutable('2026-02-15'));

        return $report;
    }

    private static function aReport(): AssetReport
    {
        $start = self::getContainer()->get(StartAssetReport::class);
        self::assertInstanceOf(StartAssetReport::class, $start);

        $report = $start->forProperty(self::PROPERTY_NUMBER, self::REPORT_YEAR, 'Vermögensbericht 2025');
        self::assertInstanceOf(AssetReport::class, $report);

        return $report;
    }

    private static function reports(): AssetReportRepository
    {
        $reports = self::getContainer()->get(AssetReportRepository::class);
        self::assertInstanceOf(AssetReportRepository::class, $reports);

        return $reports;
    }
}
