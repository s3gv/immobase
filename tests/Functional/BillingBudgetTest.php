<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ComposeBudget;
use App\Module\Billing\Application\CorrectBudget;
use App\Module\Billing\Application\ReleaseBudget;
use App\Module\Billing\Application\SavedByBudgets;
use App\Module\Billing\Application\StartBudget;
use App\Module\Billing\Application\SurveyBudgets;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetIsIncomplete;
use App\Module\Billing\Domain\BudgetPosition;
use App\Module\Billing\Domain\BudgetReference;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\CostBearing;
use App\Module\Billing\Domain\Funding;
use App\Module\Billing\Domain\LevyPurpose;
use App\Module\Billing\Domain\MeasureKind;
use App\Module\Billing\Domain\ProposedBudget;
use App\Module\Billing\Domain\Resolution;
use App\Module\Finance\Contract\CostDirectory;
use App\Module\Finance\Contract\DecidedMeasures;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use App\Module\Finance\Domain\LoanRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Budgetplan.
 *
 * Die Zusicherung, an der alles haengt: **aus dem Abstimmungsergebnis folgt
 * der Verteilerkreis.** § 21 WEG laesst bei einer baulichen Veraenderung alle
 * tragen, wenn mit doppelt qualifizierter Mehrheit beschlossen wurde oder die
 * Massnahme sich rechnet — sonst nur die Zustimmenden. Wer das falsch
 * verteilt, laesst Eigentuemer zahlen, die nicht zahlen muessen.
 *
 * Die zweite: **die Summe der Sonderumlagen ist auf den Cent der Beschluss.**
 */
final class BillingBudgetTest extends WebTestCase
{
    use BuildsABillableProperty;

    private const int FIRST_YEAR = 2027;

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

    /** Eine Erhaltungsmassnahme tragen alle — nach Stimmen fragt niemand. */
    public function testMaintenanceIsBorneByEveryone(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        $proposal = self::composed($budget);

        self::assertSame(CostBearing::Everyone, $proposal->bearing);
        self::assertCount(2, $proposal->shares, 'Beide Einheiten');
    }

    /**
     * Mit doppelt qualifizierter Mehrheit tragen alle — § 21 Abs. 2 Nr. 1 WEG.
     *
     * Beides muss zusammenkommen: mehr als zwei Drittel der abgegebenen
     * Stimmen **und** die Haelfte der Miteigentumsanteile.
     */
    public function testAQualifiedMajorityMakesEveryoneBearTheCosts(): void
    {
        $budget = self::aBudget(MeasureKind::Structural);
        self::decided($budget, cast: 3, for: 3, agreed: [self::unitId(0)]);

        $proposal = self::composed($budget);

        self::assertSame(CostBearing::QualifiedMajority, $proposal->bearing);
        self::assertCount(2, $proposal->shares, 'Auch die Einheit, die nicht zugestimmt hat');
    }

    /**
     * Ohne sie tragen nur die Zustimmenden — § 21 Abs. 3 WEG.
     *
     * Hier stimmt nur eine von zwei Einheiten zu, und ihre 250 von 450
     * Anteilen sind zwar die Haelfte, die Stimmen aber nicht die zwei Drittel.
     */
    public function testWithoutItOnlyThoseWhoAgreedBearTheCosts(): void
    {
        $budget = self::aBudget(MeasureKind::Structural);
        self::decided($budget, cast: 2, for: 1, agreed: [self::unitId(0)]);

        $proposal = self::composed($budget);

        self::assertSame(CostBearing::OnlyThoseWhoAgreed, $proposal->bearing);
        self::assertCount(1, $proposal->shares, 'Nur die zustimmende Einheit');
        self::assertSame(1200000, $proposal->shares[0]->share->cents(), 'Sie trägt alles');
    }

    /**
     * Und ohne die Haelfte der Anteile auch nicht.
     *
     * Die kleinere Einheit stimmt zu: drei von drei Stimmen, aber nur 200 von
     * 450 Anteilen. Das Gesetz verlangt beides.
     */
    public function testTheSharesCountAsWellAsTheVotes(): void
    {
        $budget = self::aBudget(MeasureKind::Structural);
        self::decided($budget, cast: 3, for: 3, agreed: [self::unitId(1)]);

        self::assertSame(CostBearing::OnlyThoseWhoAgreed, self::composed($budget)->bearing);
    }

    /**
     * Genau zwei Drittel sind nicht mehr als zwei Drittel.
     *
     * § 21 Abs. 2 Nr. 1 WEG sagt „mehr als", und das ist woertlich zu nehmen:
     * bei drei abgegebenen Stimmen genuegen zwei nicht. An dieser einen Stelle
     * entscheidet ein Groesserzeichen darueber, wer zahlt.
     */
    public function testExactlyTwoThirdsIsNotEnough(): void
    {
        $budget = self::aBudget(MeasureKind::Structural);
        self::decided($budget, cast: 3, for: 2, agreed: [self::unitId(0)]);

        self::assertSame(CostBearing::OnlyThoseWhoAgreed, self::composed($budget)->bearing);
    }

    /**
     * Was sich rechnet, tragen alle — § 21 Abs. 2 Nr. 2 WEG.
     *
     * Auch ohne qualifizierte Mehrheit. Genau das ist der Weg, ueber den eine
     * Photovoltaikanlage in aller Regel geht.
     */
    public function testAnAmortisingMeasureIsBorneByEveryone(): void
    {
        $budget = self::aBudget(MeasureKind::Structural, amortisesIn: 8);
        self::decided($budget, cast: 2, for: 1, agreed: [self::unitId(0)]);

        $proposal = self::composed($budget);

        self::assertSame(CostBearing::Amortising, $proposal->bearing);
        self::assertCount(2, $proposal->shares);
    }

    /** Zwanzig Jahre sind kein angemessener Zeitraum mehr. */
    public function testAMeasureThatTakesTooLongDoesNotCount(): void
    {
        $budget = self::aBudget(MeasureKind::Structural, amortisesIn: 20);
        self::decided($budget, cast: 2, for: 1, agreed: [self::unitId(0)]);

        self::assertSame(CostBearing::OnlyThoseWhoAgreed, self::composed($budget)->bearing);
    }

    /**
     * Die Summe der Sonderumlagen ist auf den Cent der Beschluss.
     *
     * Auch in Raten: drei Raten je Einheit ergeben zusammen den Anteil, und
     * alle Anteile zusammen die Umlage.
     */
    public function testTheLeviesAddUpToTheResolvedAmount(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, Funding::none()->byLevy(
            Money::fromCents(1200000),
            new DateTimeImmutable('2027-03-01'),
            3,
            Interval::Quarterly,
        ));

        $proposal = self::composed($budget);
        $levied = Money::zero();

        foreach ($proposal->shares as $share) {
            $parts = Money::zero();

            foreach ($share->levyParts as $part) {
                $parts = $parts->plus($part);
            }

            self::assertTrue($parts->equals($share->levy), 'Die Raten ergeben den Anteil');
            $levied = $levied->plus($share->levy);
        }

        self::assertSame(1200000, $levied->cents(), 'Und die Anteile die Umlage');
        self::assertCount(3, $proposal->levyDates, 'Drei Fälligkeiten');
    }

    /** Eine Deckungsluecke haelt den Beschluss auf. */
    public function testAGapStopsTheResolution(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, Funding::none()->fromTheReserve(Money::fromCents(500000)));

        self::assertFalse(self::composed($budget)->isCovered());

        $this->expectException(BudgetIsIncomplete::class);
        self::release($budget);
    }

    /** Mit dem Beschluss wird die Sonderumlage faellig. */
    public function testTheResolutionMakesTheLevyDue(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, Funding::none()->byLevy(
            Money::fromCents(1200000),
            new DateTimeImmutable('2027-03-01'),
            1,
            Interval::Once,
        ));
        self::release($budget);

        $payments = self::getContainer()->get(AdvancePaymentRepository::class);
        self::assertInstanceOf(AdvancePaymentRepository::class, $payments);
        $due = $payments->forYear(self::unitIds(), 2027);
        $levies = array_values(array_filter(
            $due,
            static fn ($payment): bool => AdvanceKind::SpecialLevy === $payment->kind(),
        ));

        self::assertCount(2, $levies, 'Je Einheit eine');
        self::assertSame(
            1200000,
            array_sum(array_map(static fn ($levy): int => $levy->expected()->cents(), $levies)),
        );
    }

    /**
     * Der Weg der Sonderumlage steht danach an der Zahlung.
     *
     * Wer spaeter fragt, ob dieses Geld in die Abrechnung gehoert, soll nicht
     * erst den Budgetplan suchen muessen — die Zahlung sagt es selbst.
     */
    public function testALevyForTheReserveBecomesAPaymentThatSaysSo(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, Funding::none()->byLevy(
            Money::fromCents(1200000),
            new DateTimeImmutable('2027-03-01'),
            1,
            Interval::Once,
            LevyPurpose::ForTheReserve,
        ));
        self::release($budget);

        foreach (self::leviesFor(self::unitId(0)) as $payment) {
            self::assertSame(AdvanceKind::ReserveLevy, $payment->kind());
            self::assertTrue($payment->kind()->feedsTheReserve());
        }
    }

    /**
     * Bei einer baulichen Veraenderung gibt es den Weg nicht.
     *
     * Die Erhaltungsruecklage ist zweckgebunden und gehoert allen nach
     * Miteigentumsanteilen; wer nach § 21 Abs. 3 WEG allein zahlt, legte sein
     * Geld dort in das Vermoegen aller. Was jemand eintraegt, aendert daran
     * nichts — die Massnahme entscheidet.
     */
    public function testAStructuralMeasureNeverRoutesItsLevyThroughTheReserve(): void
    {
        $budget = self::aBudget(MeasureKind::Structural);
        self::fundIt($budget, Funding::none()->byLevy(
            Money::fromCents(1200000),
            new DateTimeImmutable('2027-03-01'),
            1,
            Interval::Once,
            LevyPurpose::ForTheReserve,
        ));

        self::assertSame(LevyPurpose::ForTheMeasure, self::reloaded($budget)->funding()->levyPurpose());

        self::decided($budget, cast: 3, for: 3, agreed: self::unitIds());
        self::release($budget);

        foreach (self::leviesFor(self::unitId(0)) as $payment) {
            self::assertSame(AdvanceKind::SpecialLevy, $payment->kind());
        }
    }

    /**
     * Ohne gezaehlte Stimmen kein Beschluss ueber eine bauliche Veraenderung.
     *
     * Bis zur Abstimmung ist der Verteilerkreis eine Annahme (§ 21 WEG). Auf
     * einer Vorlage darf sie stehen; auf einem beschlossenen Schreiben waere
     * sie die Behauptung einer Mehrheit, die niemand gezaehlt hat.
     */
    public function testAStructuralMeasureNeedsACountedVote(): void
    {
        $budget = self::aBudget(MeasureKind::Structural);

        try {
            self::release($budget);
            self::fail('Ohne Abstimmung darf das nicht durchgehen');
        } catch (BudgetIsIncomplete $refused) {
            self::assertSame('billing.budget.error.no_vote', $refused->getMessage());
        }

        self::decided($budget, cast: 3, for: 3, agreed: self::unitIds());
        self::release($budget);

        self::assertFalse(self::reloaded($budget)->stage()->isOpen(), 'Mit Abstimmung schon');
    }

    /**
     * Die Uebersicht zaehlt jede Massnahme einmal.
     *
     * Zwei Fassungen derselben Massnahme sind nicht zwei Vorhaben. Stuende
     * dort die Summe aller Fassungen, haette die Gemeinschaft sich nach jeder
     * Berichtigung scheinbar mehr vorgenommen.
     */
    public function testACorrectedMeasureIsCountedOnce(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::release($budget);

        $survey = self::getContainer()->get(SurveyBudgets::class);
        self::assertInstanceOf(SurveyBudgets::class, $survey);
        self::assertSame(1200000, $survey->planned()->cents(), 'Die erste Fassung');

        $correct = self::getContainer()->get(CorrectBudget::class);
        self::assertInstanceOf(CorrectBudget::class, $correct);
        $corrected = $correct->of($budget);

        // Die berichtigte Fassung kostet mehr — daran zeigt sich, dass sie
        // gilt und nicht die Fassung davor.
        $position = $corrected->positions()[0] ?? null;
        self::assertInstanceOf(BudgetPosition::class, $position);
        $position->describe('Anlage', self::FIRST_YEAR, Money::fromCents(1500000), '');
        self::fundIt($corrected, Funding::none()->fromTheReserve(Money::fromCents(1500000)));
        self::release($corrected);

        self::assertSame(1500000, $survey->planned()->cents(), 'Und die berichtigte statt ihrer');
    }

    /**
     * Die Nummer des Beschlusses steht an beiden Enden.
     *
     * An der Sonderumlage, die hereinkommt, und an der Kostenposition, die
     * bezahlt wird. Erst damit laesst sich sagen, ob das Geld fuer das
     * ausgegeben wurde, wofuer es beschlossen wurde.
     */
    public function testTheMeasureIsNamedOnBothSides(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, self::levyOn('2027-03-01', 1200000));
        self::release($budget);

        $measures = self::getContainer()->get(DecidedMeasures::class);
        self::assertInstanceOf(DecidedMeasures::class, $measures);
        $decided = $measures->forProperty(self::propertyId());

        self::assertCount(1, $decided, 'Eine beschlossene Maßnahme steht zur Wahl');
        $reference = $decided[0]->reference;

        foreach (self::leviesFor(self::unitId(0)) as $payment) {
            self::assertSame($reference, $payment->reference(), 'Dieselbe Nummer an der Zahlung');
        }

        self::assertSame([], self::costs()->forMeasure($reference), 'Und noch keine Rechnung dazu');
    }

    /**
     * Zwei Massnahmen am selben Tag sind zwei Forderungen.
     *
     * Die Gemeinschaft beschliesst im selben Jahr das Dach und den Aufzug,
     * beide zum 1. Maerz. Waere eine Zahlung nur durch Einheit und Tag
     * bestimmt, ersetzte der zweite Beschluss den ersten — und die Einheit
     * schuldete die Haelfte dessen, was zweimal beschlossen wurde.
     */
    public function testTwoMeasuresOnTheSameDayAreTwoClaims(): void
    {
        $roof = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($roof, self::levyOn('2027-03-01', 1200000));
        self::release($roof);

        $lift = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($lift, self::levyOn('2027-03-01', 1200000));
        self::release($lift);

        $owed = Money::zero();

        foreach (self::leviesFor(self::unitId(0)) as $payment) {
            $owed = $owed->plus($payment->expected());
        }

        self::assertCount(2, self::leviesFor(self::unitId(0)), 'Je Beschluss eine Forderung');
        self::assertSame(1333334, $owed->cents(), 'Und zusammen der Anteil an beiden Maßnahmen');
    }

    /**
     * Eine Berichtigung laesst die Sonderumlage der anderen Massnahme stehen.
     *
     * Sie nimmt zurueck, was ihre eigene Fassung davor vorsah — erkennbar an
     * der Referenz, die ueber alle Fassungen dieselbe ist.
     */
    public function testACorrectionLeavesAnotherMeasureAlone(): void
    {
        $roof = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($roof, self::levyOn('2027-03-01', 1200000));
        self::release($roof);

        $lift = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($lift, self::levyOn('2027-03-01', 1200000));
        self::release($lift);

        $correct = self::getContainer()->get(CorrectBudget::class);
        self::assertInstanceOf(CorrectBudget::class, $correct);
        $corrected = $correct->of($lift);
        self::fundIt($corrected, self::levyOn('2027-09-01', 1200000));
        self::release($corrected);

        $days = array_map(
            static fn (AdvancePayment $payment): string => $payment->dueOn()->format('Y-m-d'),
            self::leviesFor(self::unitId(0)),
        );
        sort($days);

        self::assertSame(['2027-03-01', '2027-09-01'], $days, 'Das Dach bleibt im März stehen');
    }

    /**
     * Eine Berichtigung nimmt die Raten zurueck, die sie nicht mehr vorsieht.
     *
     * Sonst schuldete die Einheit beides: die drei Vierteljahresraten der
     * ersten Fassung und die zwei Monatsraten der berichtigten. Dass der
     * Beschluss ersetzt wird und nicht nur das Blatt, muss in den Zahlungen
     * ankommen.
     */
    public function testACorrectionWithdrawsTheInstalmentsItNoLongerHas(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, Funding::none()->byLevy(
            Money::fromCents(1200000),
            new DateTimeImmutable('2027-03-01'),
            3,
            Interval::Quarterly,
        ));
        self::release($budget);
        self::assertSame(
            ['2027-03-01', '2027-06-01', '2027-09-01'],
            self::leviesOf(self::unitId(0)),
            'Drei Raten aus der ersten Fassung',
        );

        $correct = self::getContainer()->get(CorrectBudget::class);
        self::assertInstanceOf(CorrectBudget::class, $correct);
        $corrected = $correct->of($budget);

        self::fundIt($corrected, Funding::none()->byLevy(
            Money::fromCents(1200000),
            new DateTimeImmutable('2027-03-01'),
            2,
            Interval::Monthly,
        ));
        self::release($corrected);

        self::assertSame(
            ['2027-03-01', '2027-04-01'],
            self::leviesOf(self::unitId(0)),
            'Der Juni und der September sind zurueckgenommen',
        );
    }

    /**
     * Die beschlossene Zufuehrung erscheint im Wirtschaftsplan des Jahres.
     *
     * Der Budgetplan schreibt keine Vorschuesse — ueber die beschliesst die
     * Versammlung im Wirtschaftsplan. Was er tut, ist die Ruecklagenzeile
     * vorbelegen.
     */
    public function testTheSavingShowsUpInTheYearItBelongsTo(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, Funding::none()->bySaving(Money::fromCents(1200000), 3, 2027));
        self::release($budget);

        $saved = self::getContainer()->get(SavedByBudgets::class);
        self::assertInstanceOf(SavedByBudgets::class, $saved);

        self::assertSame(400000, $saved->inYear(self::propertyId(), 2027)->cents());
        self::assertSame(400000, $saved->inYear(self::propertyId(), 2029)->cents());
        self::assertSame(0, $saved->inYear(self::propertyId(), 2030)->cents(), 'Danach nicht mehr');
    }

    /**
     * Der beschlossene Finanzierungsweg wird zum gefuehrten Darlehen.
     *
     * Im Budgetplan steht der Plan, eines aufzunehmen; gefuehrt wird es in
     * den Finanzen. Ohne diesen Schritt bliebe es eine Zahl auf einem Blatt —
     * und Wirtschaftsplan und Vermoegensbericht wuessten nichts davon.
     */
    public function testAResolvedLoanBecomesALoanOnFile(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, Funding::none()->byLoan(Money::fromCents(1200000), 390, Money::fromCents(20000), null));
        self::release($budget);

        $loans = self::getContainer()->get(LoanRepository::class);
        self::assertInstanceOf(LoanRepository::class, $loans);
        $found = $loans->forProperty(self::propertyId());

        self::assertCount(1, $found, 'Ein Beschluss, ein Darlehen');
        self::assertSame(1200000, $found[0]->amount()->cents());
        self::assertSame(390, $found[0]->terms()->rateBps());
        self::assertNotSame('', $found[0]->reference(), 'Es weiß, aus welchem Beschluss es stammt');
    }

    /** Ohne Darlehen im Beschluss entsteht auch keines. */
    public function testAResolutionWithoutALoanRecordsNone(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance);
        self::fundIt($budget, self::levyOn('2027-03-01', 1200000));
        self::release($budget);

        $loans = self::getContainer()->get(LoanRepository::class);
        self::assertInstanceOf(LoanRepository::class, $loans);

        self::assertSame([], $loans->forProperty(self::propertyId()));
    }

    /**
     * Eine laufende Massnahme ohne eine einzige Rechnung steht auf der Uebersicht.
     *
     * Eine Sonderumlage ist ein Vorschuss. Ist das Jahr angefangen und es
     * steht keine Rechnung dagegen, liegt das Geld irgendwo — das fragt
     * niemand von selbst, und darum muss die Uebersicht es sagen.
     */
    public function testARunningMeasureWithoutCostsIsFlagged(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance, firstYear: self::thisYear());
        self::fundIt($budget, self::levyOn(self::thisYear().'-03-01', 1200000));
        self::release($budget);

        self::assertCount(1, self::budgetOverview()['unspent']);
    }

    /** Mit einer zugeordneten Kostenposition verschwindet der Hinweis. */
    public function testAMeasureWithCostsIsNotFlagged(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance, firstYear: self::thisYear());
        self::fundIt($budget, self::levyOn(self::thisYear().'-03-01', 1200000));
        self::release($budget);
        self::alsoCostsForTheMeasure(
            BudgetReference::forTheMeasure($budget),
            Money::fromCents(1200000),
            self::thisYear(),
        );

        self::assertSame([], self::budgetOverview()['unspent']);
    }

    /**
     * Was erst naechstes Jahr anfaengt, ist kein Rueckstand.
     *
     * Sonst stuende die Warnung ab dem Beschluss jahrelang da, und wer sie
     * gewohnheitsmaessig uebersieht, uebersieht auch die echten.
     */
    public function testAMeasureThatHasNotStartedIsNotFlagged(): void
    {
        $budget = self::aBudget(MeasureKind::Maintenance, firstYear: self::thisYear() + 2);
        self::fundIt($budget, self::levyOn(self::thisYear() + 2 .'-03-01', 1200000));
        self::release($budget);

        self::assertSame([], self::budgetOverview()['unspent']);
    }

    /** Ein Entwurf zaehlt als Entwurf und nicht als Beschluss. */
    public function testADraftIsCountedAsWaiting(): void
    {
        self::aBudget(MeasureKind::Maintenance);
        $overview = self::budgetOverview();

        self::assertSame(1, $overview['drafts']);
        self::assertSame(0, $overview['planned']->cents(), 'Beschlossen ist noch nichts');
    }

    private static function thisYear(): int
    {
        return (int) (new DateTimeImmutable('today'))->format('Y');
    }

    /**
     * @return array{planned: Money, drafts: int, open: list<Budget>, unspent: list<Budget>}
     */
    private static function budgetOverview(): array
    {
        $survey = self::getContainer()->get(SurveyBudgets::class);
        self::assertInstanceOf(SurveyBudgets::class, $survey);

        return $survey->overview();
    }

    private static function costs(): CostDirectory
    {
        $found = self::getContainer()->get(CostDirectory::class);
        self::assertInstanceOf(CostDirectory::class, $found);

        return $found;
    }

    private static function levyOn(string $day, int $cents): Funding
    {
        return Funding::none()->byLevy(Money::fromCents($cents), new DateTimeImmutable($day), 1, Interval::Once);
    }

    /**
     * Die Sonderumlagen einer Einheit im Jahr 2027.
     *
     * @return list<AdvancePayment>
     */
    private static function leviesFor(string $unitId): array
    {
        $payments = self::getContainer()->get(AdvancePaymentRepository::class);
        self::assertInstanceOf(AdvancePaymentRepository::class, $payments);

        $levies = array_values(array_filter(
            $payments->forYear([$unitId], 2027),
            static fn (AdvancePayment $payment): bool => $payment->kind()->isALevy(),
        ));

        self::assertNotSame([], $levies, 'Der Beschluss hat keine einzige Rate geschrieben');

        return $levies;
    }

    /**
     * Die Faelligkeiten der Sonderumlagen einer Einheit.
     *
     * @return list<string>
     */
    private static function leviesOf(string $unitId): array
    {
        $payments = self::getContainer()->get(AdvancePaymentRepository::class);
        self::assertInstanceOf(AdvancePaymentRepository::class, $payments);

        $days = [];

        foreach ($payments->forYear([$unitId], 2027) as $payment) {
            if (AdvanceKind::SpecialLevy === $payment->kind()) {
                $days[] = $payment->dueOn()->format('Y-m-d');
            }
        }

        sort($days);

        return $days;
    }

    private static function aBudget(MeasureKind $kind, ?int $amortisesIn = null, ?int $firstYear = null): Budget
    {
        $start = self::getContainer()->get(StartBudget::class);
        self::assertInstanceOf(StartBudget::class, $start);

        $budget = $start->forProperty(self::PROPERTY_NUMBER, $firstYear ?? self::FIRST_YEAR, 'Photovoltaik', $kind->value);
        self::assertInstanceOf(Budget::class, $budget);

        if (null !== $amortisesIn) {
            $budget->plan(\App\Module\Billing\Domain\Measure::of(
                $budget->measure()->label(),
                $kind,
                self::FIRST_YEAR,
                $amortisesIn,
            ));
        }

        $position = $budget->positions()[0] ?? null;
        self::assertInstanceOf(BudgetPosition::class, $position);
        $position->describe('Anlage', self::FIRST_YEAR, Money::fromCents(1200000), '');
        $budget->fund(Funding::none()->fromTheReserve(Money::fromCents(1200000)));
        self::budgets()->save($budget);

        return $budget;
    }

    private static function fundIt(Budget $budget, Funding $funding): void
    {
        $budget->fund($funding);
        self::budgets()->save($budget);
    }

    /**
     * @param list<string> $agreed
     */
    private static function decided(Budget $budget, int $cast, int $for, array $agreed): void
    {
        $budget->decide(Resolution::of(new DateTimeImmutable('2026-11-14'), 'beschlossen', '2026/07'), $cast, $for);
        self::budgets()->save($budget);
        self::budgets()->replaceApprovals($budget->id(), $agreed);
    }

    private static function release(Budget $budget): void
    {
        $release = self::getContainer()->get(ReleaseBudget::class);
        self::assertInstanceOf(ReleaseBudget::class, $release);
        $release->release($budget, new DateTimeImmutable('2026-11-15'));
    }

    private static function composed(Budget $budget): ProposedBudget
    {
        $compose = self::getContainer()->get(ComposeBudget::class);
        self::assertInstanceOf(ComposeBudget::class, $compose);

        return $compose->of(self::reloaded($budget));
    }

    private static function reloaded(Budget $budget): Budget
    {
        $found = self::budgets()->byId($budget->id());
        self::assertInstanceOf(Budget::class, $found);

        return $found;
    }

    /** Die wievielte Einheit des Objekts — sie gibt es, die Zusicherung steht hier. */
    private static function unitId(int $at): string
    {
        $id = self::unitIds()[$at] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private static function budgets(): BudgetRepository
    {
        $budgets = self::getContainer()->get(BudgetRepository::class);
        self::assertInstanceOf(BudgetRepository::class, $budgets);

        return $budgets;
    }
}
