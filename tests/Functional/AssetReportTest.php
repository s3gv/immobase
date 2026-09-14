<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\AssetReportGaps;
use App\Module\Billing\Application\ComposeAssetReport;
use App\Module\Billing\Application\CorrectAssetReport;
use App\Module\Billing\Application\ReleaseAssetReport;
use App\Module\Billing\Application\StartAssetReport;
use App\Module\Billing\Domain\AssetItem;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportIsIncomplete;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\ReportedAssets;
use App\Module\Billing\Domain\ReportYearIsNotOver;
use App\Module\Finance\Application\SaveLoan;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanEventKind;
use App\Module\Finance\Domain\LoanTerms;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Vermoegensbericht nach § 28 Abs. 4 WEG.
 *
 * Die Zusicherung, an der alles haengt: **der Bericht spricht ueber einen
 * Stichtag und nicht ueber heute.** Was danach gebucht wird, gehoert in den
 * naechsten; was danach herausgegeben wurde, aendert sich gar nicht mehr.
 *
 * Die zweite: **dasselbe Geld wird nicht zweimal gezaehlt.** Die
 * Erhaltungsruecklage liegt auf einem der Konten — sie steht daneben als
 * Zweckbindung und nicht darunter als weiteres Vermoegen.
 */
final class AssetReportTest extends WebTestCase
{
    use BuildsABillableProperty;
    private const int REPORT_YEAR = 2025;

    protected function setUp(): void
    {
        self::bootKernel();
        self::buildTheProperty();
    }

    protected function tearDown(): void
    {
        self::removeTheProperty();

        parent::tearDown();
    }

    /** Der Ruecklagenstand ist der des Stichtags — nicht der von heute. */
    public function testTheReserveIsTheBalanceOnTheReportingDate(): void
    {
        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(500000), '2024-01-01');
        self::alsoMovedTheReserve(ReserveMovementKind::Contribution, Money::fromCents(120000), '2025-06-30');
        self::alsoMovedTheReserve(ReserveMovementKind::Withdrawal, Money::fromCents(80000), '2025-09-15');
        // Nach dem Stichtag gebucht — sie gehoert in den naechsten Bericht.
        self::alsoMovedTheReserve(ReserveMovementKind::Contribution, Money::fromCents(999900), '2026-03-01');

        $reserve = self::composed(self::aReport())->reserve;

        self::assertSame(500000, $reserve->opening()->cents(), 'Was am 31.12.2024 dastand');
        self::assertSame(120000, $reserve->contributions()->cents());
        self::assertSame(-80000, $reserve->withdrawals()->cents(), 'Entnahmen mindern — sie stehen negativ da');
        self::assertSame(540000, $reserve->closing()->cents(), 'Anfang plus Zufuehrung minus Entnahme');
    }

    /**
     * Der Schlussbestand ist die gerade Summe der Zeilen darueber.
     *
     * Wer die Spalte nachrechnet, kommt heraus, wo der Bericht herauskommt —
     * sonst waere die Entwicklung eine Zierde neben einer Zahl.
     */
    public function testTheDevelopmentAddsUpToTheClosingBalance(): void
    {
        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(400000), '2023-01-01');
        self::alsoMovedTheReserve(ReserveMovementKind::Contribution, Money::fromCents(60000), '2025-03-01');
        self::alsoMovedTheReserve(ReserveMovementKind::SpecialLevy, Money::fromCents(250000), '2025-04-01');
        self::alsoMovedTheReserve(ReserveMovementKind::Interest, Money::fromCents(-1250), '2025-12-30');
        self::alsoMovedTheReserve(ReserveMovementKind::Withdrawal, Money::fromCents(150000), '2025-11-02');

        $reserve = self::composed(self::aReport())->reserve;
        $added = $reserve->opening()
            ->plus($reserve->contributions())
            ->plus($reserve->specialLevies())
            ->plus($reserve->interest())
            ->plus($reserve->withdrawals());

        self::assertTrue($added->equals($reserve->closing()), 'Die Zeilen ergeben den Schlussbestand');
    }

    /**
     * Ein Anfangsbestand **im** Berichtsjahr ist der Anfangsbestand.
     *
     * Er wird auf den ersten Tag des ersten Jahres gebucht und faellt damit in
     * den ersten Bericht. Unter den Zufuehrungen stuende dort, die Eigentuemer
     * haetten vierzigtausend Euro eingezahlt; ganz weggelassen ginge die
     * Spalte nicht mehr auf den Schlussbestand auf.
     */
    public function testAnOpeningBalanceInsideTheYearIsTheOpeningBalance(): void
    {
        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(4250000), '2025-01-01');
        self::alsoMovedTheReserve(ReserveMovementKind::Contribution, Money::fromCents(120000), '2025-06-30');

        $reserve = self::composed(self::aReport())->reserve;

        self::assertSame(4250000, $reserve->opening()->cents(), 'Er steht als Anfangsbestand da');
        self::assertSame(120000, $reserve->contributions()->cents(), 'Und nicht als Zuführung');
        self::assertSame(4370000, $reserve->closing()->cents());
        self::assertTrue(
            $reserve->opening()
                ->plus($reserve->contributions())
                ->plus($reserve->specialLevies())
                ->plus($reserve->interest())
                ->plus($reserve->withdrawals())
                ->equals($reserve->closing()),
            'Die Spalte geht auf',
        );
    }

    /**
     * Ein Rueckstand aus dem Vorjahr ist am Stichtag immer noch eine Forderung.
     *
     * Ein Bericht, der nur das Berichtsjahr saehe, rechnete die Gemeinschaft
     * aermer, als sie ist.
     */
    public function testArrearsFromAnEarlierYearAreStillAClaim(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2024);
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025, Money::fromCents(10000));

        $claims = self::composed(self::aReport())->claims;

        self::assertCount(1, $claims, 'Eine Einheit schuldet etwas');
        self::assertSame(50000, $claims[0]->amount->cents(), '300 ganz und 200 vom Rest');
        self::assertSame(2024, $claims[0]->since, 'Seit dem Jahr, in dem es anfing');
    }

    /**
     * Nebenkostenvorauszahlungen sind keine Forderung der Gemeinschaft.
     *
     * Sie schuldet der Mieter seinem Vermieter. In einer Aufstellung des
     * Gemeinschaftsvermoegens waere sie fremdes Geld.
     */
    public function testOperatingCostAdvancesAreNotACommunityClaim(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(25000), 2025, null, AdvanceKind::OperatingCosts);

        self::assertSame([], self::composed(self::aReport())->claims);
    }

    /**
     * Eine unbezahlte Sonderumlage ist eine Forderung der Gemeinschaft.
     *
     * Sie schuldet der Eigentuemer, genau wie das Hausgeld — und § 28 Abs. 4
     * WEG verlangt die Forderungen in der Aufstellung. Sie wegzulassen
     * rechnete die Gemeinschaft aermer, als sie ist.
     */
    public function testAnUnpaidSpecialLevyIsAClaimAsWell(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(150000), 2025, null, AdvanceKind::SpecialLevy);

        $claims = self::composed(self::aReport())->claims;

        self::assertCount(1, $claims);
        self::assertSame(150000, $claims[0]->amount->cents());
    }

    /**
     * Ein Gegenstand ohne Wert wird genannt und nicht mitgezaehlt.
     *
     * Eine Null waere die Behauptung, er sei nichts wert.
     */
    public function testAnUnvaluedHoldingIsListedButNotCounted(): void
    {
        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(250000));
        self::itemise($report, AssetKind::Holding, 'Gartengeräte', null);

        $body = self::composed($report);

        self::assertCount(1, $body->of(AssetKind::Holding), 'Aufgeführt ist er');
        self::assertSame(1, $body->unvalued(), 'Und als unbewertet gezählt');
        self::assertSame(250000, $body->total()->cents(), 'Aber nicht in der Summe');
    }

    /** Guthaben plus Forderungen plus bewertete Gegenstaende, minus Verbindlichkeiten. */
    public function testTheTotalIsWhatTheRowsSay(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(250000));
        self::itemise($report, AssetKind::Liability, 'Dachdecker', Money::fromCents(90000));
        self::itemise($report, AssetKind::Holding, 'Heizölvorrat', Money::fromCents(45000));

        self::assertSame(235000, self::composed($report)->total()->cents());
    }

    /**
     * Die Ruecklage wird nicht zusaetzlich gezaehlt.
     *
     * Ihr Geld liegt auf dem Konto, das jemand als zweckgebunden markiert hat.
     * Was danebensteht, ist die Deckung — und die fehlt hier.
     */
    public function testTheEarmarkedAccountIsNotCountedOnTopOfTheReserve(): void
    {
        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(300000), '2024-01-01');

        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Rücklagenkonto', Money::fromCents(200000), earmarked: true);

        $body = self::composed($report);

        self::assertSame(200000, $body->total()->cents(), 'Das Vermögen ist, was auf dem Konto liegt');
        self::assertSame(300000, $body->reserve->closing()->cents(), 'Die Rücklage steht daneben');
        self::assertSame(100000, $body->missingFromTheReserve()?->cents(), 'Und es fehlen 1.000 Euro');
    }

    /**
     * Ohne ein zweckgebundenes Konto gibt es die Deckungsfrage nicht.
     *
     * Sonst waere die Meldung eine Auskunft ueber die Eingabe und nicht ueber
     * das Geld: niemand hat gesagt, wo die Ruecklage liegt.
     */
    public function testWithoutAnEarmarkedAccountNothingIsClaimedAboutCover(): void
    {
        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(300000), '2024-01-01');

        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(200000));

        self::assertNull(self::composed($report)->missingFromTheReserve());
    }

    /** Ein Konto ohne Stand haelt die Herausgabe auf. */
    public function testAMissingAmountStopsTheRelease(): void
    {
        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', null);

        self::assertCount(1, AssetReportGaps::of($report));

        $this->expectException(AssetReportIsIncomplete::class);
        self::release($report);
    }

    /**
     * Was nach der Herausgabe gebucht wird, aendert den Bericht nicht mehr.
     *
     * Auch nicht, wenn es auf einen Tag vor dem Stichtag gebucht wird: das
     * Blatt ist zugestellt, und was daran falsch ist, wird in einer
     * berichtigten Fassung richtiggestellt.
     */
    public function testABookingAfterTheReleaseDoesNotChangeTheReport(): void
    {
        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(300000), '2024-01-01');

        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(300000), earmarked: true);
        self::release($report);

        self::alsoMovedTheReserve(ReserveMovementKind::Withdrawal, Money::fromCents(120000), '2025-07-01');
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        $body = self::composed(self::reloaded($report));

        self::assertSame(300000, $body->reserve->closing()->cents(), 'Eingefroren heißt eingefroren');
        self::assertSame([], $body->claims, 'Auch die Forderungen');
    }

    /** Je Einheit ein Empfaenger — wem sie am Stichtag gehoerte. */
    public function testEveryUnitGetsALetter(): void
    {
        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(100000));
        self::release($report);

        self::assertCount(2, self::reloaded($report)->documents());
    }

    /**
     * Die berichtigte Fassung behaelt die Betraege.
     *
     * Derselbe Stichtag, dieselben Konten — falsch war eine einzelne Angabe.
     * Wer alles neu eintippen muesste, tippt sich einen neuen Fehler.
     */
    public function testTheCorrectedVersionKeepsTheAmounts(): void
    {
        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(100000));
        self::release($report);

        $corrections = self::getContainer()->get(CorrectAssetReport::class);
        self::assertInstanceOf(CorrectAssetReport::class, $corrections);
        $corrected = $corrections->of(self::reloaded($report));

        self::assertSame($report->edition()->number(), $corrected->edition()->number(), 'Derselbe Vorgang');
        self::assertSame(2, $corrected->edition()->iteration());

        $item = $corrected->items()[0] ?? null;
        self::assertInstanceOf(AssetItem::class, $item);
        self::assertSame('Girokonto', $item->label());
        self::assertSame(100000, $item->amount()?->cents(), 'Mitsamt Betrag');
    }

    /**
     * Der naechste Jahresbericht uebernimmt die Bezeichnungen, nicht die Betraege.
     *
     * Ein vorbelegter Kontostand von vor einem Jahr saehe aus wie eingegeben
     * und waere die gefaehrlichste Zahl im ganzen Bericht.
     */
    public function testNextYearCarriesTheLabelsWithoutTheAmounts(): void
    {
        $report = self::aReport(self::REPORT_YEAR - 1);
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(100000));
        self::release($report);

        $item = self::aReport()->items()[0] ?? null;

        self::assertInstanceOf(AssetItem::class, $item);
        self::assertSame('Girokonto', $item->label(), 'Die Bezeichnung kommt mit');
        self::assertFalse($item->isValued(), 'Der Betrag nicht');
    }

    /** Ueber ein Jahr, das noch laeuft, gibt es keinen Bericht. */
    public function testAReportForARunningYearIsRefused(): void
    {
        $this->expectException(ReportYearIsNotOver::class);
        self::aReport((int) (new DateTimeImmutable('today'))->format('Y'));
    }

    /**
     * Die Restschuld eines Darlehens mindert das Vermoegen — gerechnet.
     *
     * Sie steht im Tilgungsplan der Finanzen. Als erfasste Verbindlichkeit
     * waere sie eine Zahl, die jemand einmal im Jahr abschreibt — und danach
     * die zweite Wahrheit neben der, die sich rechnen laesst.
     */
    public function testALoanReducesTheAssetsWithoutBeingTyped(): void
    {
        self::alsoOwesALoan();

        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(250000));
        $body = self::composed($report);

        self::assertCount(1, $body->debts, 'Ein Darlehen, eine Zeile');
        self::assertSame('Dachsanierung · Prüfbank', $body->debts[0]->label);
        self::assertSame(250000 - $body->borrowed()->cents(), $body->total()->cents(), 'Es mindert das Vermögen');
    }

    /** Was bei der Herausgabe offen war, bleibt auf dem Blatt stehen. */
    public function testAnExtraRepaymentAfterTheReleaseDoesNotChangeTheReport(): void
    {
        $loan = self::alsoOwesALoan();

        $report = self::aReport();
        self::itemise($report, AssetKind::Bank, 'Girokonto', Money::fromCents(250000));
        self::release($report);
        $owed = self::composed(self::reloaded($report))->borrowed();

        self::loans()->record(
            $loan,
            LoanEventKind::ExtraPayment,
            new DateTimeImmutable('2025-06-01'),
            Money::fromCents(1000000),
            null,
            '',
        );

        self::assertSame(
            $owed->cents(),
            self::composed(self::reloaded($report))->borrowed()->cents(),
            'Eingefroren heißt eingefroren',
        );
        self::assertFalse($owed->isZero(), 'Und es stand wirklich etwas offen');
    }

    private static function alsoOwesALoan(): Loan
    {
        $loan = self::loans()->forProperty(self::propertyId(), LoanTerms::withPayment(
            Money::fromCents(12000000),
            390,
            new DateTimeImmutable('2024-04-01'),
            Money::fromCents(85000),
        ));
        self::loans()->describe($loan, 'Dachsanierung', 'Prüfbank', '');

        return $loan;
    }

    private static function loans(): SaveLoan
    {
        $save = self::getContainer()->get(SaveLoan::class);
        self::assertInstanceOf(SaveLoan::class, $save);

        return $save;
    }

    private static function aReport(int $year = self::REPORT_YEAR): AssetReport
    {
        $start = self::getContainer()->get(StartAssetReport::class);
        self::assertInstanceOf(StartAssetReport::class, $start);

        $report = $start->forProperty(self::PROPERTY_NUMBER, $year, 'Vermögensbericht '.$year);
        self::assertInstanceOf(AssetReport::class, $report);

        return $report;
    }

    /**
     * Eine Position ausfuellen — und dabei die leere Zeile benutzen, die
     * schon dasteht.
     *
     * Genau das tut auch jemand am Bildschirm: ein frischer Bericht bringt
     * eine leere Kontozeile mit, und wer eintraegt, fuellt sie aus, statt
     * eine zweite anzulegen.
     */
    private static function itemise(
        AssetReport $report,
        AssetKind $kind,
        string $label,
        ?Money $amount,
        bool $earmarked = false,
    ): void {
        $item = self::anEmptyRow($report, $kind) ?? new AssetItem($report, \count($report->items()) + 1, $kind);
        $item->describe($label, $amount, $earmarked, '');
        self::reports()->save($report);
    }

    private static function anEmptyRow(AssetReport $report, AssetKind $kind): ?AssetItem
    {
        foreach ($report->items() as $item) {
            if ($kind === $item->kind() && '' === $item->label()) {
                return $item;
            }
        }

        return null;
    }

    private static function composed(AssetReport $report): ReportedAssets
    {
        $compose = self::getContainer()->get(ComposeAssetReport::class);
        self::assertInstanceOf(ComposeAssetReport::class, $compose);

        return $compose->of($report);
    }

    private static function release(AssetReport $report): void
    {
        $release = self::getContainer()->get(ReleaseAssetReport::class);
        self::assertInstanceOf(ReleaseAssetReport::class, $release);
        $release->release($report, new DateTimeImmutable('2026-02-15'));
    }

    private static function reloaded(AssetReport $report): AssetReport
    {
        $found = self::reports()->byId($report->id());
        self::assertInstanceOf(AssetReport::class, $found);

        return $found;
    }

    private static function reports(): AssetReportRepository
    {
        $reports = self::getContainer()->get(AssetReportRepository::class);
        self::assertInstanceOf(AssetReportRepository::class, $reports);

        return $reports;
    }
}
