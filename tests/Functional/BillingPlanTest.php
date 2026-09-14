<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\CheckPlanCorrections;
use App\Module\Billing\Application\ComposePlan;
use App\Module\Billing\Application\CorrectPlan;
use App\Module\Billing\Application\PlanFromLastYear;
use App\Module\Billing\Application\ProposePlan;
use App\Module\Billing\Application\ReleasePlan;
use App\Module\Billing\Domain\LetterContents;
use App\Module\Billing\Domain\MissingFigure;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanDocument;
use App\Module\Billing\Domain\PlanDrift;
use App\Module\Billing\Domain\PlanHasDrifted;
use App\Module\Billing\Domain\PlanLineKind;
use App\Module\Billing\Domain\PlannedDocument;
use App\Module\Billing\Domain\PlannedLine;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Billing\Domain\PlanProposal;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Finance\Application\SaveHouseMoney;
use App\Module\Finance\Application\SaveLoan;
use App\Module\Finance\Contract\AdvanceSchedules;
use App\Module\Finance\Domain\HouseMoney;
use App\Module\Finance\Domain\HouseMoneyRepository;
use App\Module\Finance\Domain\LoanTerms;
use App\Module\Property\Domain\Measures;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\Unit;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Wirtschaftsplan.
 *
 * Die Zusicherung, an der alles haengt: **die Summe der Einzelwirtschaftsplaene
 * ist auf den Cent der Gesamtplan.** Was eine Gemeinschaft aufbringen soll,
 * muss genau das sein, was ihre Eigentuemer zusammen zahlen — sonst plant sie
 * ein Loch oder einen Ueberschuss, den niemand beschlossen hat.
 *
 * Die zweite: **was beschlossen wurde, steht in der Hausgeldstaffel.** Solange
 * der Plan nur einen Vorschlag machte und die Staffel daneben von Hand
 * gepflegt wuerde, rechnete die Jahresabrechnung gegen die falsche Zahl.
 */
final class BillingPlanTest extends WebTestCase
{
    use BuildsABillableProperty;

    private const int PLAN_YEAR = 2027;

    protected function setUp(): void
    {
        self::bootKernel();
        self::buildTheProperty();
        self::alsoCostsByMea(Money::fromCents(300000));
        self::alsoPaidIntoTheReserve(Money::fromCents(60000));
    }

    protected function tearDown(): void
    {
        self::removeTheProperty();

        parent::tearDown();
    }

    /** Die Zeilen kommen aus dem Ist des Vorjahres — und die Ruecklage dazu. */
    public function testTheRowsArePrefilledFromLastYear(): void
    {
        $plan = self::aPlan();
        $costs = self::positionsOf($plan, PlanLineKind::Cost);

        self::assertCount(3, $costs, 'Drei Kostenpositionen aus 2026');
        self::assertSame(
            ['Prüfsteuer', 'Prüfdienst', 'Prüfverwaltung'],
            array_map(static fn (PlanPosition $p): string => $p->costKindLabel(), $costs),
        );

        foreach ($costs as $position) {
            self::assertTrue(
                $position->planned()->equals($position->previous()),
                $position->costKindLabel().': vorbelegt heißt „so wie voriges Jahr"',
            );
        }

        $reserve = self::positionsOf($plan, PlanLineKind::Reserve);
        self::assertCount(1, $reserve, 'Die Rücklage kommt immer dazu');
        self::assertTrue(
            self::firstOf($reserve)->previous()->equals(Money::fromCents(120000)),
            'Vorbelegt mit dem, was voriges Jahr zugeführt wurde',
        );
        self::assertSame('mea', self::firstOf($reserve)->key()->kind(), 'Nach Miteigentumsanteilen');
    }

    /** Die Summe der Einzelplaene ist auf den Cent der Gesamtplan. */
    public function testTheSumOfTheUnitPlansIsExactlyTheWholePlan(): void
    {
        $proposal = self::composed(self::aPlan());
        $shares = Money::zero();

        foreach ($proposal->documents as $document) {
            $shares = $shares->plus($document->yearly());
        }

        self::assertTrue(
            $shares->equals(Money::fromCents(124050 + 48000 + 300000 + 120000)),
            'Kosten und Zuführung gehen restlos auf die Einheiten',
        );
        self::assertTrue($proposal->reserve()->equals(Money::fromCents(120000)), 'Die Zuführung eigens ausgewiesen');
        self::assertTrue($proposal->yearly()->equals($shares), 'Der Gesamtplan ist die Summe seiner Teile');
    }

    /**
     * Nach Miteigentumsanteilen verteilt — der haeufigste Schluessel einer WEG.
     *
     * 250 und 200 von zusammen 450 gehaltenen Anteilen. Der Nenner ist, was
     * die Einheiten zusammen halten, und nicht der des Objekts: verteilt wird
     * auf die, die es gibt.
     */
    public function testItDistributesByCoOwnershipShares(): void
    {
        $proposal = self::composed(self::aPlan());
        $line = self::lineOf(self::documentAt($proposal, 0), 'Prüfverwaltung');

        self::assertSame('250', $line->distribution->shareOf());
        self::assertSame('450', $line->distribution->shareTotal());
        self::assertTrue($line->amount->equals(Money::fromCents(166667)));

        self::assertTrue(
            $line->amount
                ->plus(self::lineOf(self::documentAt($proposal, 1), 'Prüfverwaltung')->amount)
                ->equals(Money::fromCents(300000)),
            'Zusammen genau der Gesamtbetrag',
        );
    }

    /**
     * Ein einmaliger Posten plant null — und bleibt trotzdem stehen.
     *
     * Der haeufigste Fehler der Praxis ist, eine Dachreparatur unbesehen
     * fortzuschreiben. Die Zeile verschwinden zu lassen waere der zweite: wer
     * sie sucht, faende sie nicht und fragte sich, ob sie vergessen wurde.
     */
    public function testAOneOffPositionPlansNothingAndStaysVisible(): void
    {
        $plan = self::aPlan();
        $position = self::firstOf(self::positionsOf($plan, PlanLineKind::Cost));
        $position->plan($position->previous(), true, 'Einmalige Nachzahlung 2026');
        self::plans()->save($plan);

        $line = self::lineOf(self::documentAt(self::composed($plan), 0), 'Prüfsteuer');

        self::assertTrue($line->total->isZero(), 'Geplant wird nichts');
        self::assertTrue($line->amount->isZero());
        self::assertTrue($line->previous->equals(Money::fromCents(124050)), 'Der Vorjahreswert bleibt lesbar');
        self::assertSame('Einmalige Nachzahlung 2026', $line->reason);
    }

    /** Zwoelf gleiche Raten, und der Jahresanteil daneben. */
    public function testTheAdvanceIsTheYearlyShareRoundedUpPerPayment(): void
    {
        $document = self::documentAt(self::composed(self::aPlan()), 0);

        // 2.588,69 € Kosten und 666,67 € Zuführung.
        self::assertTrue($document->costs()->equals(Money::fromCents(258869)));
        self::assertTrue($document->reserve()->equals(Money::fromCents(66667)));
        self::assertTrue($document->yearly()->equals(Money::fromCents(325536)));
        // 325536 durch zwoelf sind 27128 — hier geht es glatt auf.
        self::assertTrue($document->advance()->equals(Money::fromCents(27128)));
        self::assertTrue(
            $document->advance()->multipliedBy(12)->cents() >= $document->yearly()->cents(),
            'Die Gemeinschaft nimmt nie zu wenig ein',
        );
    }

    /** Die Freigabe schreibt die Hausgeldstaffel. */
    public function testReleasingWritesTheHouseMoneySchedule(): void
    {
        $plan = self::released(self::aPlan());
        $step = self::stepOn(self::firstDocument($plan)->unitId(), '2027-01-01');

        self::assertInstanceOf(HouseMoney::class, $step, 'Zum ersten Fälligkeitstag steht eine Stufe');
        self::assertTrue($step->amount()->equals(Money::fromCents(27128)));
        self::assertSame('WP-'.self::PROPERTY_NUMBER.'/1-2027-'.$plan->edition()->number().'-1', $step->origin()->planReference());
        self::assertFalse($step->origin()->wasChangedByHand());
    }

    /**
     * Wer die Stufe von Hand aendert, aendert sie von Hand — und das steht da.
     *
     * Die Versammlung darf anders beschliessen, und die Verwaltung darf sich
     * vertippen. Was nicht sein darf, ist dass eine stillschweigend geaenderte
     * Zahl wie ein Beschluss aussieht.
     */
    public function testAHandChangeKeepsTheOriginAndSaysSo(): void
    {
        $plan = self::released(self::aPlan());
        $step = self::stepOn(self::firstDocument($plan)->unitId(), '2027-01-01');
        self::assertInstanceOf(HouseMoney::class, $step);

        self::advances()->change($step, $step->startsOn(), Money::fromCents(22000), $step->interval());

        $changed = self::stepOn(self::firstDocument($plan)->unitId(), '2027-01-01');
        self::assertInstanceOf(HouseMoney::class, $changed);
        self::assertTrue($changed->origin()->isFromAPlan(), 'Die Herkunft bleibt');
        self::assertTrue($changed->origin()->wasChangedByHand());
    }

    /**
     * Aendert sich die Grundlage, meldet sich die Korrektur — und ersetzt die
     * Stufe desselben Tages.
     *
     * Ein Vorschuss ist kein Saldo, sondern ein Betrag, der ab einem Tag gilt.
     * Zwei Stufen zum selben Tag waeren zwei Antworten auf dieselbe Frage.
     */
    public function testACorrectionReplacesTheStepOfTheSameDay(): void
    {
        $plan = self::released(self::aPlan());
        $unitId = self::firstDocument($plan)->unitId();

        self::assertSame([], self::corrections()->pending(), 'Frisch freigegeben stimmt alles');

        self::growTheFirstUnitTo('88.40');

        self::assertArrayHasKey($plan->id(), self::corrections()->pending());
        self::assertCount(2, self::corrections()->changed($plan), 'Eine Flächenänderung trifft beide Einheiten');

        $correction = self::correct()->of($plan);
        self::assertSame(2, $correction->edition()->iteration());
        self::assertCount(4, $correction->positions(), 'Die Zeilen kommen mit');

        self::released($correction);

        self::assertCount(1, self::stepsOf($unitId, '2027-01-01'), 'Eine Stufe zum 1. Januar, nicht zwei');
        $step = self::stepOn($unitId, '2027-01-01');
        self::assertInstanceOf(HouseMoney::class, $step);
        self::assertStringEndsWith('-2', $step->origin()->planReference() ?? '');
        self::assertSame([], self::corrections()->pending(), 'Danach ist wieder alles aktuell');
    }

    /**
     * Eine herausgegebene Vorlage aendert sich nicht mehr — auch nicht, wenn
     * sich ihre Grundlage aendert.
     *
     * Betraege, Verteilungswerte und Empfaenger haengen an Angaben, die
     * ausserhalb des Plans stehen: Flaeche, Miteigentumsanteil, fester Anteil,
     * erfasster Verbrauch, wer die Einheit besitzt. Wer dort etwas berichtigt,
     * faesst den Plan nicht an — und veraenderte trotzdem jedes Blatt, das
     * noch gerechnet wuerde. „Herausgegeben am 30. Oktober" stuende dann ueber
     * Zahlen, die an diesem Tag niemand gesehen hat.
     */
    public function testAnIssuedProposalSurvivesAChangeToItsBasis(): void
    {
        $plan = self::proposed(self::aPlan());
        $asIssued = self::lettersOf($plan);

        self::growTheFirstUnitTo('88.40');
        self::forgetEverything();

        $again = self::plans()->byId($plan->id());
        self::assertInstanceOf(Plan::class, $again);

        self::assertSame($asIssued, self::lettersOf($again), 'Das Blatt bleibt, wie es herausging');
        self::assertNotSame(
            $asIssued,
            LetterContents::of(self::composed($again)->documents),
            'Heute käme etwas anderes heraus — genau darum wird eingefroren',
        );
    }

    /**
     * Und beschlossen wird, was vorlag.
     *
     * Zwischen Versammlung und Freigabe kann sich die Grundlage aendern.
     * Rechnete die Freigabe neu, stuenden in der Staffel Betraege, ueber die
     * niemand abgestimmt hat.
     */
    public function testTheReleaseTakesWhatWasProposed(): void
    {
        $plan = self::proposed(self::aPlan());
        $asIssued = self::lettersOf($plan);
        $advance = self::firstDocument($plan)->advance();

        self::growTheFirstUnitTo('88.40');
        self::forgetEverything();

        $again = self::plans()->byId($plan->id());
        self::assertInstanceOf(Plan::class, $again);
        self::released($again, despiteDrift: true);

        self::assertSame($asIssued, self::lettersOf($again), 'Eingefroren wird das Vorgelegte');

        $step = self::stepOn(self::firstDocument($again)->unitId(), '2027-01-01');
        self::assertInstanceOf(HouseMoney::class, $step);
        self::assertTrue($step->amount()->equals($advance), 'Und die Staffel trägt genau diesen Betrag');
    }

    /**
     * Aber nicht versehentlich.
     *
     * Die Betraege gelten ein Jahr lang. Wer sie festschreibt, obwohl heute
     * andere herauskaemen, darf das — er muss es nur wissen. Die Pruefung
     * steht in der Freigabe und nicht nur auf einer Seite: die Schritte sind
     * einzeln erreichbar, und wer gleich auf den Beschluss springt, haette den
     * Hinweis nie gesehen.
     */
    public function testADriftedPlanIsNotReleasedByAccident(): void
    {
        $plan = self::proposed(self::aPlan());
        self::growTheFirstUnitTo('88.40');
        self::forgetEverything();

        $again = self::plans()->byId($plan->id());
        self::assertInstanceOf(Plan::class, $again);

        try {
            self::released($again);
            self::fail('Die Freigabe hätte anhalten müssen');
        } catch (PlanHasDrifted $halt) {
            self::assertSame('billing.plan.error.drifted', $halt->getMessage());
        }

        self::forgetEverything();
        $stillOpen = self::plans()->byId($plan->id());
        self::assertInstanceOf(Plan::class, $stillOpen);
        self::assertTrue($stillOpen->stage()->isOpen(), 'Und nichts festgeschrieben');
    }

    /**
     * Auch eine umbenannte Einheit ist eine Abweichung.
     *
     * Sie steht im Betreff des Schreibens und in der Einheitenwahl. Bliebe sie
     * unbemerkt, saehe die Vorlage heute anders aus, und niemand wuesste es —
     * obwohl sich keine Zahl geruehrt hat.
     */
    public function testRenamingAUnitCountsAsDrift(): void
    {
        $plan = self::proposed(self::aPlan());

        self::assertFalse(PlanDrift::between($plan, self::composed($plan)), 'Frisch vorgelegt stimmt alles');

        self::renameTheFirstUnitTo('WE 1, Erdgeschoss links');
        self::forgetEverything();

        $again = self::plans()->byId($plan->id());
        self::assertInstanceOf(Plan::class, $again);

        self::assertTrue(PlanDrift::between($again, self::composed($again)));
    }

    /**
     * Scheitert die Staffel, bleibt nichts halb Beschlossenes stehen.
     *
     * Der Plan wird beschlossen **und** je Einheit entsteht eine
     * Hausgeldstufe. Ginge das zweite schief, stuende ein beschlossener Plan
     * mit halb geschriebener Staffel da — und ein zweiter Versuch waere
     * gesperrt, weil der Plan ja schon beschlossen ist. Genau der Zustand,
     * aus dem niemand mehr herauskommt.
     */
    public function testAFailedScheduleLeavesNothingBehind(): void
    {
        $plan = self::aPlan();
        $unitId = self::unitIds()[0] ?? '';

        try {
            self::releaseWith(self::aScheduleThatFails())
                ->release($plan, new DateTimeImmutable('2026-11-20'), despiteDrift: false);
            self::fail('Die Staffel hätte streiken müssen');
        } catch (RuntimeException $failure) {
            self::assertSame('Die Staffel streikt.', $failure->getMessage());
        }

        // Frisch aus der Datenbank: im Speicher steht der Plan freigegeben da,
        // aber zurueckgerollt ist, was zaehlt.
        self::forgetEverything();
        $again = self::plans()->byId($plan->id());

        self::assertInstanceOf(Plan::class, $again);
        self::assertTrue($again->stage()->isOpen(), 'Nicht beschlossen');
        self::assertSame([], $again->documents(), 'Und keine Schreiben eingefroren');
        self::assertSame([], self::stepsOf($unitId, '2027-01-01'), 'Und keine halbe Staffel');
    }

    /** Ohne Eigentuemer kein Einzelplan — und darum keine Freigabe. */
    public function testAUnitWithoutAnOwnerBlocksTheRelease(): void
    {
        self::dropTheOwnersOfTheSecondUnit();

        $proposal = self::composed(self::aPlan());

        self::assertCount(1, $proposal->documents, 'Nur die Einheit mit Eigentümer bekommt Post');
        self::assertFalse($proposal->isComplete());
        $gap = $proposal->missing[0] ?? null;
        self::assertInstanceOf(MissingFigure::class, $gap);
        self::assertSame('billing.plan.missing.owner', $gap->whatKey);
    }

    /**
     * Ein gefuehrtes Darlehen bringt zwei Zeilen mit — Zins und Tilgung.
     *
     * Getrennt, weil es zweierlei ist: der Zins ist Aufwand, die Tilgung
     * schichtet Vermoegen um. Zusammen sind sie die Jahresrate, und die ist
     * das, was abfliesst und eingesammelt werden muss.
     */
    public function testALoanBringsTwoRowsIntoThePlan(): void
    {
        self::alsoOwesALoan(Money::fromCents(12000000), Money::fromCents(85000));
        $plan = self::aPlan();
        $rows = [];

        foreach (self::positionsOf($plan, PlanLineKind::Cost) as $position) {
            $rows[$position->costKindLabel()] = $position->planned();
        }

        self::assertArrayHasKey('Darlehenszinsen', $rows);
        self::assertArrayHasKey('Darlehenstilgung', $rows);
        self::assertSame(
            12 * 85000,
            $rows['Darlehenszinsen']->plus($rows['Darlehenstilgung'])->cents(),
            'Zwölf Raten im Planjahr',
        );
    }

    /** Ohne Darlehen stehen die beiden Zeilen gar nicht erst da. */
    public function testWithoutALoanThereAreNoLoanRows(): void
    {
        $labels = array_map(
            static fn (PlanPosition $p): string => $p->costKindLabel(),
            self::positionsOf(self::aPlan(), PlanLineKind::Cost),
        );

        self::assertNotContains('Darlehenszinsen', $labels, 'Zwei Nullen wären zwei Zeilen zum Abstimmen');
    }

    /**
     * @param list<PlanPosition> $positions
     */
    private static function firstOf(array $positions): PlanPosition
    {
        $position = $positions[0] ?? null;
        self::assertInstanceOf(PlanPosition::class, $position);

        return $position;
    }

    private static function firstDocument(Plan $plan): PlanDocument
    {
        $document = $plan->documents()[0] ?? null;
        self::assertInstanceOf(PlanDocument::class, $document);

        return $document;
    }

    /** Ein Darlehen des Objekts, das im Planjahr laeuft. */
    private static function alsoOwesALoan(Money $amount, Money $payment): void
    {
        $save = self::getContainer()->get(SaveLoan::class);
        self::assertInstanceOf(SaveLoan::class, $save);
        $save->forProperty(self::propertyId(), LoanTerms::withPayment(
            $amount,
            390,
            new DateTimeImmutable(self::PLAN_YEAR - 1 .'-01-15'),
            $payment,
        ));
    }

    private static function aPlan(): Plan
    {
        $plan = new Plan(
            self::plans()->nextNumber(),
            self::propertyId(),
            self::PROPERTY_NUMBER,
            self::aFiscalYear(self::PLAN_YEAR),
        );
        $plan->describe('Wirtschaftsplan '.self::PLAN_YEAR);
        self::plans()->save($plan);

        $fill = self::getContainer()->get(PlanFromLastYear::class);
        self::assertInstanceOf(PlanFromLastYear::class, $fill);
        $fill->fill($plan);
        self::plans()->save($plan);

        return $plan;
    }

    private static function proposed(Plan $plan): Plan
    {
        $propose = self::getContainer()->get(ProposePlan::class);
        self::assertInstanceOf(ProposePlan::class, $propose);
        $propose->propose($plan, new DateTimeImmutable('2026-10-30'));

        return $plan;
    }

    /** Was auf den Blaettern dieses Plans steht — eingefroren gelesen. */
    private static function lettersOf(Plan $plan): string
    {
        return LetterContents::of(array_map(
            static fn (PlanDocument $document): PlannedDocument => $document->asPlanned(),
            $plan->documents(),
        ));
    }

    private static function released(Plan $plan, bool $despiteDrift = false): Plan
    {
        $release = self::getContainer()->get(ReleasePlan::class);
        self::assertInstanceOf(ReleasePlan::class, $release);
        $release->release($plan, new DateTimeImmutable('2026-11-20'), $despiteDrift);

        return $plan;
    }

    /**
     * Dieselbe Freigabe, aber die Staffel streikt.
     *
     * Von Hand zusammengesetzt und nicht ueber den Container: der Ersatz soll
     * genau an einer Stelle stecken, und zwar an der, die scheitern koennen
     * muss.
     */
    private static function releaseWith(AdvanceSchedules $schedules): ReleasePlan
    {
        $compose = self::getContainer()->get(ComposePlan::class);
        self::assertInstanceOf(ComposePlan::class, $compose);

        return new ReleasePlan(self::plans(), $compose, $schedules);
    }

    private static function aScheduleThatFails(): AdvanceSchedules
    {
        return new class implements AdvanceSchedules {
            public function decided(array $advances): void
            {
                throw new RuntimeException('Die Staffel streikt.');
            }
        };
    }

    /** Alles vergessen, was im Speicher steht — damit die Datenbank antwortet. */
    private static function forgetEverything(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }

    private static function composed(Plan $plan): PlanProposal
    {
        $compose = self::getContainer()->get(ComposePlan::class);
        self::assertInstanceOf(ComposePlan::class, $compose);

        return $compose->of($plan);
    }

    /**
     * @return list<PlanPosition>
     */
    private static function positionsOf(Plan $plan, PlanLineKind $kind): array
    {
        return array_values(array_filter(
            $plan->positions(),
            static fn (PlanPosition $position): bool => $position->lineKind() === $kind,
        ));
    }

    private static function documentAt(PlanProposal $proposal, int $at): PlannedDocument
    {
        $document = $proposal->documents[$at] ?? null;
        self::assertInstanceOf(PlannedDocument::class, $document);

        return $document;
    }

    private static function lineOf(PlannedDocument $document, string $costKind): PlannedLine
    {
        foreach ($document->lines as $line) {
            if ($line->costKind === $costKind) {
                return $line;
            }
        }

        self::fail('Keine Zeile für '.$costKind);
    }

    private static function stepOn(string $unitId, string $day): ?HouseMoney
    {
        return self::stepsOf($unitId, $day)[0] ?? null;
    }

    /**
     * @return list<HouseMoney>
     */
    private static function stepsOf(string $unitId, string $day): array
    {
        // Keine Staffel heisst keine Stufen — das ist eine Antwort und kein
        // Fehler, und genau die, auf die der Rueckroll-Test wartet.
        $schedule = self::steps()->forUnits([$unitId])[$unitId] ?? null;

        return array_values(array_filter(
            $schedule?->steps() ?? [],
            static fn (HouseMoney $step): bool => $step->startsOn()->format('Y-m-d') === $day,
        ));
    }

    private static function growTheFirstUnitTo(string $area): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);
        $unit = $property->units()[0] ?? null;
        self::assertInstanceOf(Unit::class, $unit);

        $unit->measure(Measures::of($area, null, null));
        self::properties()->save($property);
    }

    private static function renameTheFirstUnitTo(string $label): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);
        $unit = $property->units()[0] ?? null;
        self::assertInstanceOf(Unit::class, $unit);

        $unit->describe($label, $unit->usage());
        self::properties()->save($property);
    }

    private static function dropTheOwnersOfTheSecondUnit(): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);
        $unit = $property->units()[1] ?? null;
        self::assertInstanceOf(Unit::class, $unit);

        foreach ($unit->owners() as $owner) {
            $unit->removeOwner($owner);
        }

        self::properties()->save($property);
    }

    private static function plans(): PlanRepository
    {
        $found = self::getContainer()->get(PlanRepository::class);
        self::assertInstanceOf(PlanRepository::class, $found);

        return $found;
    }

    private static function steps(): HouseMoneyRepository
    {
        $found = self::getContainer()->get(HouseMoneyRepository::class);
        self::assertInstanceOf(HouseMoneyRepository::class, $found);

        return $found;
    }

    private static function advances(): SaveHouseMoney
    {
        $found = self::getContainer()->get(SaveHouseMoney::class);
        self::assertInstanceOf(SaveHouseMoney::class, $found);

        return $found;
    }

    private static function corrections(): CheckPlanCorrections
    {
        $found = self::getContainer()->get(CheckPlanCorrections::class);
        self::assertInstanceOf(CheckPlanCorrections::class, $found);

        return $found;
    }

    private static function correct(): CorrectPlan
    {
        $found = self::getContainer()->get(CorrectPlan::class);
        self::assertInstanceOf(CorrectPlan::class, $found);

        return $found;
    }
}
