<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Finance\Application\RecordAmounts;
use App\Module\Finance\Application\RecordQuantities;
use App\Module\Finance\Application\SaveCostItem;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemFilter;
use App\Module\Finance\Domain\CostItemHasBeenRecorded;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\CostItemYearUnit;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\EntryMode;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\NothingToMeasure;
use App\Module\Finance\Domain\QuantitiesAreRecorded;
use App\Module\Finance\Domain\QuantityCannotBeCleared;
use App\Module\Finance\Domain\RecordedInTheMeantime;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Finance\Domain\UnitOfMeasure;
use App\Module\Finance\Domain\YearAlreadyRecorded;
use App\Module\Finance\UserInterface\Controller\CostItemFlow;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Eine Kostenposition anlegen — und was dabei nicht durchgeht.
 *
 * Wie beim Objekt und beim Mietverhaeltnis speichert jeder Schritt sofort.
 * Der Unterschied: eine Kostenposition blockiert nichts und muss nichts
 * abschliessen — sie steht ab dem ersten Schritt in der Liste.
 */
final class CostItemFlowTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;
    use UsesASecondConnection;

    private const string PROPERTY = 'Kostenobjekt Prüfung';

    private const string OTHER_PROPERTY = 'Fremdobjekt Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Schritt eins legt die Position an — mit Objekt, Art und Schluessel. */
    public function testTheFirstStepCreatesTheItem(): void
    {
        $client = self::editor();
        $property = self::givenProperty();

        self::post($client, '/finanzen/kosten/neu', '/finanzen/kosten/neu', [
            'propertyId' => $property->id(),
            'kindId' => self::kind()->id(),
            'keyId' => self::key()->id(),
        ]);

        $item = self::found();
        self::assertGreaterThanOrEqual(40001, $item->number());
        self::assertSame($property->id(), $item->propertyId());
        // Ohne Zutun monatlich zum Ersten: was am haeufigsten vorkommt.
        self::assertSame(Interval::Monthly, $item->due()->interval());
        self::assertSame(1, $item->due()->day());
    }

    /** Fehlt eines der drei, entsteht nichts. */
    public function testAnIncompleteAssignmentCreatesNothing(): void
    {
        $client = self::editor();
        self::givenProperty();

        self::post($client, '/finanzen/kosten/neu', '/finanzen/kosten/neu', [
            'propertyId' => '',
            'kindId' => self::kind()->id(),
            'keyId' => self::key()->id(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], self::all(), 'Es ist keine Position entstanden');
    }

    /** Ein Faelligkeitstag, den es nicht in jedem Monat gibt, kommt nicht durch. */
    public function testADayBeyondTheTwentyEighthIsRefused(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::post(
            $client,
            '/finanzen/kosten/'.$item->number().'/bearbeiten/faelligkeit',
            '/finanzen/kosten/'.$item->number().'/bearbeiten/faelligkeit',
            ['interval' => 'monthly', 'dueDay' => '31', 'direction' => 'forward'],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(1, self::found()->due()->day(), 'Der 31. wurde nicht gespeichert');
    }

    /** Zu einer jaehrlichen Faelligkeit gehoert ein Monat. */
    public function testAYearlyItemNeedsAMonth(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::post(
            $client,
            '/finanzen/kosten/'.$item->number().'/bearbeiten/faelligkeit',
            '/finanzen/kosten/'.$item->number().'/bearbeiten/faelligkeit',
            ['interval' => 'annually', 'dueDay' => '15', 'dueMonth' => '', 'direction' => 'forward'],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(Interval::Monthly, self::found()->due()->interval(), 'Nichts wurde gespeichert');
    }

    /**
     * Die Umlagefaehigkeit kommt von der Kostenart — bis jemand widerspricht.
     */
    public function testApportionabilityFollowsTheKindUntilOverridden(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::assertTrue($item->isApportionable(), 'Grundsteuer ist umlagefähig');
        self::assertFalse($item->overridesApportionability());

        self::post(
            $client,
            '/finanzen/kosten/'.$item->number().'/bearbeiten/sonstiges',
            '/finanzen/kosten/'.$item->number().'/bearbeiten/sonstiges',
            ['apportionable' => 'no', 'split' => 'by_day', 'note' => '', 'direction' => 'forward'],
        );

        $fresh = self::found();
        self::assertFalse($fresh->isApportionable());
        self::assertTrue($fresh->overridesApportionability(), 'Die Abweichung steht als solche da');
    }

    /** Tagesgenau ist die Vorgabe; der Stichtag wird bewusst gewaehlt. */
    public function testSplittingByDayIsTheDefault(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::assertTrue($item->splitsByDay());

        self::post(
            $client,
            '/finanzen/kosten/'.$item->number().'/bearbeiten/sonstiges',
            '/finanzen/kosten/'.$item->number().'/bearbeiten/sonstiges',
            ['apportionable' => '', 'split' => 'on_the_day', 'note' => 'Zwischenablesung', 'direction' => 'forward'],
        );

        $fresh = self::found();
        self::assertFalse($fresh->splitsByDay());
        self::assertSame('Zwischenablesung', $fresh->note());
    }

    /** Jeder Schritt und jeder Abschnitt zeichnet sich. */
    public function testEveryStepAndSectionRenders(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        foreach (CostItemFlow::keys() as $step) {
            $client->request('GET', '/finanzen/kosten/'.$item->number().'/bearbeiten/'.$step);
            self::assertResponseIsSuccessful('Schritt '.$step);

            $client->request('GET', '/finanzen/kosten/'.$item->number().'?abschnitt='.$step);
            self::assertResponseIsSuccessful('Abschnitt '.$step);
        }
    }

    /**
     * Die Maszeinheit steht dort, wo die Mengen erfasst werden.
     *
     * Und sie faellt weg, sobald nicht mehr gemessen wird: eine Einheit ohne
     * Mengen waere ein Rest, den spaeter niemand erklaeren kann.
     */
    public function testTheMeasureFollowsTheKey(): void
    {
        $client = self::editor();
        $item = self::givenMeteredItem($client);

        self::assertTrue(self::found()->isMetered());
        self::assertSame(UnitOfMeasure::CubicMetre, self::found()->measure());

        self::measuring($client, $item->number(), self::key()->id(), 'm3');

        self::assertFalse(self::found()->isMetered());
        self::assertNull(self::found()->measure(), 'Ohne Erfassung keine Maßeinheit');
    }

    /**
     * Ohne Maszeinheit gibt es nichts einzutragen.
     *
     * Eine Zahl ohne Einheit ist keine Menge: „84,250" waeren Kubikmeter
     * oder Kilowattstunden, und das laesst sich spaeter nicht mehr
     * entscheiden.
     */
    public function testWithoutAMeasureThereAreNoQuantityFields(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);
        self::measuring($client, $item->number(), self::key(true)->id(), '');
        self::givenYear($client, self::found());

        $client->request('GET', '/finanzen/kosten/'.$item->number().'/bearbeiten/betraege');
        $body = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('name="consumption[', $body, 'Keine Felder');
        self::assertStringContainsString('Maßeinheit', $body, 'Sondern der Hinweis');
    }

    /** Steht die Einheit fest, steht je Einheit eine Zeile da — mit ihrem Kürzel. */
    public function testWithAMeasureEveryUnitGetsARow(): void
    {
        $client = self::editor();
        $item = self::givenMeteredItem($client);
        self::givenYear($client, self::found());

        $client->request('GET', '/finanzen/kosten/'.$item->number().'/bearbeiten/betraege');
        $body = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('name="consumption[', $body);
        self::assertStringContainsString('m³', $body, 'Die Menge trägt ihre Einheit');
    }

    /**
     * Eine untergeschobene Einheit kommt nicht in die Jahressumme.
     *
     * Bei „fertig verteilt" ist der Gesamtbetrag die Summe der
     * Einzelbetraege. Eine Einheit aus einem anderen Objekt wuerde in der
     * Ansicht nicht auftauchen — ihr Betrag zaehlte aber mit.
     */
    public function testAForeignUnitIsRefusedWhenRecordingQuantities(): void
    {
        self::bootKernel();
        $item = self::plainItem(true);
        $year = new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Distributed);
        self::items()->save($item);

        $this->expectException(UnitBelongsElsewhere::class);

        self::quantities()->meter($year, [
            self::givenOtherUnit()->id() => ['consumption' => '10,000', 'amount' => Money::fromCents(20000)],
        ]);
    }

    /** Wo nicht nach Verbrauch verteilt wird, gibt es nichts zu messen. */
    public function testQuantitiesAreRefusedWhereNothingIsMeasured(): void
    {
        self::bootKernel();
        $item = self::plainItem(false);
        $year = new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Total);
        self::items()->save($item);

        $this->expectException(NothingToMeasure::class);

        self::quantities()->meter($year, []);
    }

    /**
     * Zwei gleichzeitig erfasste Jahreswerte — der zweite bekommt eine
     * Antwort.
     *
     * Die eigene Verbindung liest in einer Transaktion mit stabilem Blick;
     * die zweite legt den Wert darin an. Die Pruefung sieht ihn deshalb
     * nicht mehr, der eindeutige Index schon.
     */
    public function testAYearRecordedInTheMeantimeIsRefusedReadably(): void
    {
        self::bootKernel();
        $item = self::plainItem(false);
        $connection = self::entityManager()->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            // Nicht über found(): das leert den Entity Manager, und die
            // Position wäre danach eine andere Instanz derselben Zeile.
            self::assertNull($item->years()->forYear(2026));

            $this->recordedByAnotherRequest($item->id());

            self::record()->add($item, 2026, Money::fromCents(48000), EntryMode::Total);
            self::fail('Der zweite Jahreswert kam durch.');
        } catch (YearAlreadyRecorded $problem) {
            self::assertNotSame('', $problem->getMessage(), 'Und zwar mit einer lesbaren Meldung');
        } finally {
            $connection->rollBack();
            $this->closeSecondConnection();
        }
    }

    /**
     * Eine Position mit erfassten Betraegen wird beendet, nicht geloescht.
     *
     * Sie kann Grundlage einer Abrechnung sein: abgerechnet wird das
     * vergangene Jahr, manchmal das vorletzte. Ein Loeschen naehme die
     * Jahreswerte und alle erfassten Mengen stillschweigend mit.
     */
    public function testARecordedItemIsEndedAndNotDeleted(): void
    {
        self::bootKernel();
        $item = self::plainItem(false);
        new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Total);
        self::items()->save($item);

        try {
            self::save()->remove($item);
            self::fail('Die erfasste Position wurde gelöscht.');
        } catch (CostItemHasBeenRecorded) {
            self::assertNotNull(self::items()->byNumber($item->number()), 'Sie steht noch da');
        }

        self::save()->runUntil($item, new DateTimeImmutable('today'));

        self::assertTrue($item->isPast());
        self::assertFalse($item->years()->isEmpty(), 'Und ihre Beträge stehen noch da');
    }

    /** Ohne einen einzigen Betrag ist sie ein Entwurf — und loeschbar. */
    public function testAnItemWithoutAmountsCanStillBeDeleted(): void
    {
        self::bootKernel();
        $item = self::plainItem(false);

        self::save()->remove($item);

        self::assertNull(self::items()->byNumber($item->number()));
    }

    /**
     * Beendete stehen bereit, aber nicht im Weg.
     *
     * Und der Umlagefilter fragt eine Spalte ab, die zu einem eingebetteten
     * Wertobjekt gehoert — genau daran ist er schon einmal gescheitert.
     */
    public function testTheListShowsWhatIsRunningAndTheFiltersHold(): void
    {
        self::bootKernel();
        $running = self::plainItem(false);
        $ended = self::plainItem(false);
        self::save()->runUntil($ended, new DateTimeImmutable('today'));

        self::assertSame([$running->number()], self::numbersMatching(false));
        self::assertSame(
            [$running->number(), $ended->number()],
            self::numbersMatching(true),
            'Mit Schalter steht beides da',
        );

        // Grundsteuer ist umlagefähig — der Filter muss beide finden.
        self::assertSame(2, self::items()->countMatching(
            CostItemFilter::of(null, null, 'yes', null, true),
        ));
    }

    /**
     * Sind Mengen erfasst, liegen Objekt und Schluessel fest.
     *
     * Die Mengen haengen an Einheiten. Zoege die Position zu einem anderen
     * Objekt, blieben sie bei den Einheiten des alten: die Seite zeigte sie
     * nicht mehr, und bei „fertig verteilt" flossen ihre Betraege trotzdem
     * weiter in die Jahressumme.
     */
    public function testAnItemWithRecordedQuantitiesCannotMove(): void
    {
        self::bootKernel();
        $item = self::plainItem(true);
        $item->measureIn(UnitOfMeasure::CubicMetre);
        $year = new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Distributed);
        self::items()->save($item);
        self::quantities()->meter($year, [
            self::ownUnit()->id() => ['consumption' => '10,000', 'amount' => Money::fromCents(48000)],
        ]);

        $this->expectException(QuantitiesAreRecorded::class);

        self::save()->belongsTo(
            $item,
            self::givenOtherProperty()->id(),
            self::kind(),
            self::key(true),
        );
    }

    /** Und der Schluessel darf auch nicht von der Erfassung weg. */
    public function testTheKeyCannotLeaveMeteringWithRecordedQuantities(): void
    {
        self::bootKernel();
        $item = self::plainItem(true);
        $item->measureIn(UnitOfMeasure::CubicMetre);
        $year = new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Distributed);
        self::items()->save($item);
        self::quantities()->meter($year, [
            self::ownUnit()->id() => ['consumption' => '10,000', 'amount' => Money::fromCents(48000)],
        ]);

        $this->expectException(QuantitiesAreRecorded::class);

        self::save()->measuredBy($item, self::key(), null);
    }

    /**
     * Zwei gleichzeitig erfasste Mengen — die zweite bekommt eine Antwort.
     *
     * Beide sehen noch keine Zeile zu dieser Einheit und legen beide eine
     * an; der eindeutige Index faengt die zweite. Ohne Uebersetzung waere
     * das ein 500er.
     */
    public function testQuantitiesRecordedInTheMeantimeAreRefusedReadably(): void
    {
        self::bootKernel();
        $item = self::plainItem(true);
        $item->measureIn(UnitOfMeasure::CubicMetre);
        $year = new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Total);
        self::items()->save($item);
        $unit = self::ownUnit();

        $connection = self::entityManager()->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            self::assertSame([], $year->units());

            $this->meteredByAnotherRequest($year->id(), $unit->id());

            self::quantities()->meter($year, [
                $unit->id() => ['consumption' => '10,000', 'amount' => null],
            ]);
            self::fail('Die zweite Menge kam durch.');
        } catch (RecordedInTheMeantime $problem) {
            self::assertNotSame('', $problem->getMessage(), 'Und zwar mit einer lesbaren Meldung');
        } finally {
            $connection->rollBack();
            $this->closeSecondConnection();
        }
    }

    /**
     * Ein Jahr mit Mengen wird nicht entfernt.
     *
     * Mit ihm gingen seine Mengen je Einheit mit — das Orphan-Removal
     * raeumt sie stillschweigend weg. Ohne diese Sperre liesse sich die
     * Sperre an der Kostenposition umgehen: die Position bliebe stehen, ihre
     * Verteilungsgrundlage waere fort.
     */
    public function testAYearWithQuantitiesIsNotRemoved(): void
    {
        self::bootKernel();
        $year = self::givenMeteredYear();

        try {
            self::record()->drop($year);
            self::fail('Das Jahr wurde entfernt.');
        } catch (QuantitiesAreRecorded) {
            $stillThere = self::found()->years()->forYear(2026);

            self::assertNotNull($stillThere, 'Es steht noch da');
            self::assertNotSame([], $stillThere->units(), 'Und seine Mengen auch');
        }
    }

    /** Ohne Mengen ist es eine Fehleingabe — und die darf weg. */
    public function testAYearWithoutQuantitiesCanStillBeRemoved(): void
    {
        self::bootKernel();
        $item = self::plainItem(false);
        $year = new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Total);
        self::items()->save($item);

        self::record()->drop($year);

        self::assertNull(self::found()->years()->forYear(2026));
    }

    /** Und der Knopf dazu steht nur dort, wo er etwas tut. */
    public function testTheRemoveButtonIsGoneOnceQuantitiesAreRecorded(): void
    {
        $client = self::editor();
        self::givenMeteredYear();

        $client->request('GET', '/finanzen/kosten/'.self::found()->number().'/bearbeiten/betraege');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[name="remove"]', 'Kein Entfernen an diesem Jahr');
    }

    /**
     * Eine erfasste Menge laesst sich nicht leeren.
     *
     * Sonst liesse sich ein Jahr Feld fuer Feld leerraeumen und danach ganz
     * entfernen: die Sperre am Jahreswert waere einen Umweg weit umgehbar,
     * und die Verteilungsgrundlage still fort. „Kein Verbrauch" ist die
     * Null und keine Leere.
     */
    public function testARecordedQuantityCannotBeEmptied(): void
    {
        self::bootKernel();
        $year = self::givenMeteredYear();
        $unit = self::ownUnit();

        try {
            self::quantities()->meter($year, [$unit->id() => ['consumption' => '', 'amount' => null]]);
            self::fail('Die Menge wurde geleert.');
        } catch (QuantityCannotBeCleared) {
            self::assertNotSame([], self::found()->years()->forYear(2026)?->units() ?? [], 'Sie steht noch da');
        }
    }

    /** Berichtigen geht — die Null sagt „kein Verbrauch". */
    public function testARecordedQuantityIsCorrectedWithAValue(): void
    {
        self::bootKernel();
        $year = self::givenMeteredYear();

        self::quantities()->meter($year, [
            self::ownUnit()->id() => ['consumption' => '0', 'amount' => null],
        ]);

        $units = self::found()->years()->forYear(2026)?->units() ?? [];
        self::assertCount(1, $units);
        self::assertSame('0.000', $units[0]->consumption(), 'Null ist ein Wert, keine Leere');
    }

    /**
     * Und damit bleibt das Jahr gesperrt, auch nach dem Umweg.
     *
     * Genau der Weg, um den es geht: erst alle Mengen leeren, dann das Jahr
     * entfernen.
     */
    public function testAYearStaysLockedAfterTheDetour(): void
    {
        self::bootKernel();
        $year = self::givenMeteredYear();

        try {
            self::quantities()->meter($year, [
                self::ownUnit()->id() => ['consumption' => '', 'amount' => null],
            ]);
        } catch (QuantityCannotBeCleared) {
            // So gedacht — und danach ist das Jahr weiter gesperrt.
        }

        $this->expectException(QuantitiesAreRecorded::class);

        self::record()->drop($year);
    }

    /** Je Wirtschaftsjahr hoechstens ein Betrag. */
    public function testAYearIsRecordedOnlyOnce(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::year($client, $item->number(), ['fiscalYear' => '2026', 'amount' => '1.240,50']);
        self::year($client, $item->number(), ['fiscalYear' => '2026', 'amount' => '900,00']);

        $years = self::found()->years();
        self::assertCount(1, $years->all(), 'Das Jahr steht einmal da');
        self::assertSame(124050, $years->amountFor(2026)?->cents());
    }

    /**
     * Ein leeres Feld ist keine Zahl — und trotzdem kein Serverfehler.
     *
     * Der Faelligkeitstag und die Jahreszahl wurden als Zahl gelesen; ein
     * leergeraeumtes Feld beantwortete Symfony mit 400 statt mit dem Hinweis.
     * Die Jahreszahl wurde ausserdem gar nicht geprueft: die Null waere so
     * als Wirtschaftsjahr in die Abrechnung gewandert.
     */
    public function testAnEmptyNumberIsRefusedAndNotAServerError(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::post(
            $client,
            '/finanzen/kosten/'.$item->number().'/bearbeiten/faelligkeit',
            '/finanzen/kosten/'.$item->number().'/bearbeiten/faelligkeit',
            ['interval' => 'monthly', 'dueDay' => '', 'direction' => 'forward'],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(1, self::found()->due()->day(), 'Nichts wurde gespeichert');

        // Der Jahres-Knopf leitet zurueck — gepruefte Ablehnung heisst hier:
        // die Meldung kommt als Hinweis, und gespeichert wurde nichts.
        self::year($client, $item->number(), ['fiscalYear' => '', 'amount' => '1.240,50']);

        self::assertSame([], self::found()->years()->all(), 'Und kein Jahr null');
    }

    /** Zwei Jahre stehen nebeneinander — das vergangene bleibt, wie es war. */
    public function testEachYearKeepsItsOwnAmount(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::year($client, $item->number(), ['fiscalYear' => '2025', 'amount' => '1.100,00']);
        self::year($client, $item->number(), ['fiscalYear' => '2026', 'amount' => '1.240,50']);

        $years = self::found()->years();
        self::assertSame(110000, $years->amountFor(2025)?->cents());
        self::assertSame(124050, $years->amountFor(2026)?->cents());
    }

    /**
     * Die enthaltene Umsatzsteuer steht neben dem Betrag — und leer heisst null.
     *
     * Sie aendert nicht, was die Position gekostet hat; sie zaehlt nur fuer
     * Mietverhaeltnisse mit Umsatzsteuer.
     */
    public function testTheIncludedTaxIsRecordedBesideTheAmount(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::year($client, $item->number(), ['fiscalYear' => '2026', 'amount' => '1.190,00', 'inputTax' => '190,00']);
        self::year($client, $item->number(), ['fiscalYear' => '2025', 'amount' => '480,00', 'inputTax' => '']);

        $years = self::found()->years();
        self::assertSame(119000, $years->amountFor(2026)?->cents(), 'Der Betrag bleibt brutto');
        self::assertSame(19000, $years->forYear(2026)?->inputTax()->cents());
        self::assertSame(0, $years->forYear(2025)?->inputTax()->cents(), 'Leer ist null');
    }

    /** Mehr Steuer als Betrag gibt es nicht — und es wird nichts gespeichert. */
    public function testMoreTaxThanAmountIsRefused(): void
    {
        $client = self::editor();
        $item = self::givenItem($client);

        self::year($client, $item->number(), ['fiscalYear' => '2026', 'amount' => '100,00', 'inputTax' => '100,01']);

        self::assertSame([], self::found()->years()->all());
    }

    /**
     * Bei „fertig verteilt" zaehlt die Summe der Einzelbetraege — auch fuer die Steuer darin.
     *
     * 100 Euro Steuer in 50 Euro Kosten gibt es nicht. Wuerde es gespeichert,
     * setzte die Abrechnung den Nettoanteil auf null, und die Kosten
     * verschwaenden still. Und wer spaeter einen Einzelbetrag verkleinert,
     * darf die Summe nicht unter die Steuer druecken.
     */
    public function testADistributedYearCannotHoldMoreTaxThanItsParts(): void
    {
        $client = self::editor();
        $item = self::givenMeteredItem($client);
        self::distributedYear($client, $item->number(), '');
        $year = self::found()->years()->forYear(2026);
        self::assertNotNull($year);
        $unit = (string) array_key_first(self::consumptions('10'));

        self::partsOf($client, $item->number(), $year->id(), $unit, '10', '50,00');
        self::assertSame(5000, self::found()->years()->forYear(2026)?->amount()->cents(), 'Die Summe der Teile');

        self::changedYear($client, $item->number(), $year->id(), '100,00');
        self::assertSame(0, self::found()->years()->forYear(2026)?->inputTax()->cents(), '100 € Steuer in 50 € Kosten werden abgelehnt');
        self::assertStringContainsString('nicht mehr Umsatzsteuer', self::flashesOf($client));

        self::changedYear($client, $item->number(), $year->id(), '7,98');
        self::assertSame(798, self::found()->years()->forYear(2026)?->inputTax()->cents());

        self::partsOf($client, $item->number(), $year->id(), $unit, '10', '5,00');
        self::assertSame(5000, self::found()->years()->forYear(2026)?->amount()->cents(), 'Ein Einzelbetrag unter die Steuer bleibt draußen');
        self::assertStringContainsString('nicht mehr Umsatzsteuer', self::flashesOf($client));
    }

    /**
     * Ein Verbrauch mit drei Nachkommastellen ist kein Tausender.
     *
     * „84,250" ist der Zaehlerstand 84,25 — ein Faktor 1000 in einer
     * Abrechnung faellt niemandem auf.
     */
    public function testAThreeDecimalConsumptionIsNotAThousand(): void
    {
        $client = self::editor();
        $item = self::givenMeteredItem($client);
        $year = self::givenYear($client, $item);

        self::metering($client, $item->number(), $year->id(), self::consumptions('84,250'));

        self::assertSame('84.250', self::firstUnitValue()->consumption());
    }

    /**
     * Verbrauchswerte lassen sich aendern.
     *
     * Entfernen und Anlegen in derselben Runde liefe in den eindeutigen
     * Index: Doctrine fuegt erst ein und loescht dann.
     */
    public function testConsumptionValuesCanBeChanged(): void
    {
        $client = self::editor();
        $item = self::givenMeteredItem($client);
        $year = self::givenYear($client, $item);

        self::metering($client, $item->number(), $year->id(), self::consumptions('10,000'));
        self::metering($client, $item->number(), $year->id(), self::consumptions('12,500'));

        self::assertResponseRedirects();
        self::assertSame('12.500', self::firstUnitValue()->consumption());
    }

    protected static function testEmail(): string
    {
        return 'kosten@example.org';
    }

    /** Was im selben Augenblick eine andere Anfrage erfasst. */
    private function meteredByAnotherRequest(string $yearId, string $unitId): void
    {
        $this->secondConnection()->executeStatement(
            'INSERT INTO finance_cost_item_year_unit (id, year_id, unit_id, consumption, amount)
             VALUES (gen_random_uuid(), ?, ?, ?, NULL)',
            [$yearId, $unitId, '5.0000'],
        );
    }

    /**
     * @return list<int>
     */
    private static function numbersMatching(bool $withPast): array
    {
        $property = self::propertyByName();
        self::assertNotNull($property);

        return array_map(
            static fn (CostItem $item): int => $item->number(),
            self::items()->matching(
                CostItemFilter::of($property->id(), null, null, null, $withPast),
                Page::of(1, 50),
                Sort::by('nummer'),
            ),
        );
    }

    /** Was im selben Augenblick eine andere Anfrage erfasst. */
    private function recordedByAnotherRequest(string $itemId): void
    {
        $this->secondConnection()->executeStatement(
            'INSERT INTO finance_cost_item_year (id, cost_item_id, fiscal_year, amount, entry_mode)
             VALUES (gen_random_uuid(), ?, 2026, 12000, ?)',
            [$itemId, 'total'],
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private static function year(KernelBrowser $client, int $number, array $fields): void
    {
        self::post(
            $client,
            '/finanzen/kosten/'.$number.'/jahre',
            '/finanzen/kosten/'.$number.'/bearbeiten/betraege',
            [...$fields, 'mode' => 'total'],
        );
    }

    private static function givenYear(KernelBrowser $client, CostItem $item): CostItemYear
    {
        self::year($client, $item->number(), ['fiscalYear' => '2026', 'amount' => '480,00']);
        $year = self::found()->years()->forYear(2026);
        self::assertNotNull($year);

        return $year;
    }

    /**
     * @param array<string, string> $consumption
     */
    private static function metering(KernelBrowser $client, int $number, string $yearId, array $consumption): void
    {
        $step = '/finanzen/kosten/'.$number.'/bearbeiten/betraege';
        $crawler = $client->request('GET', $step);
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/finanzen/kosten/'.$number.'/jahre/'.$yearId.'/mengen', [
            'consumption' => $consumption,
            '_token' => $token,
        ]);
    }

    /** Schluessel und Maszeinheit im Schritt „Betraege" setzen. */
    private static function measuring(KernelBrowser $client, int $number, string $keyId, string $measure): void
    {
        $step = '/finanzen/kosten/'.$number.'/bearbeiten/betraege';
        $crawler = $client->request('GET', $step);
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/finanzen/kosten/'.$number.'/erfassung', [
            'keyId' => $keyId,
            'measure' => $measure,
            '_token' => $token,
        ]);
    }

    /**
     * Eine Position, die gemessen wird: Verbrauchsschluessel und Maszeinheit.
     *
     * Ohne die Maszeinheit gibt es keine Zeilen zum Ausfuellen — eine Zahl
     * ohne Einheit ist keine Menge.
     */
    private static function distributedYear(KernelBrowser $client, int $number, string $inputTax): void
    {
        self::post($client, '/finanzen/kosten/'.$number.'/jahre', '/finanzen/kosten/'.$number.'/bearbeiten/betraege', [
            'fiscalYear' => '2026', 'amount' => '0', 'mode' => 'distributed', 'inputTax' => $inputTax,
        ]);
    }

    private static function changedYear(KernelBrowser $client, int $number, string $yearId, string $inputTax): void
    {
        self::post($client, '/finanzen/kosten/'.$number.'/jahre/'.$yearId, '/finanzen/kosten/'.$number.'/bearbeiten/betraege', [
            'fiscalYear' => '2026', 'amount' => '0', 'mode' => 'distributed', 'inputTax' => $inputTax, 'save' => '1',
        ]);
    }

    private static function partsOf(KernelBrowser $client, int $number, string $yearId, string $unitId, string $consumption, string $amount): void
    {
        $crawler = $client->request('GET', '/finanzen/kosten/'.$number.'/bearbeiten/betraege');
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/finanzen/kosten/'.$number.'/jahre/'.$yearId.'/mengen', [
            'consumption' => [$unitId => $consumption],
            'amount' => [$unitId => $amount],
            '_token' => $token,
        ]);
    }

    /** Die Hinweise nach der Weiterleitung — dort, wo der Mensch sie liest. */
    private static function flashesOf(KernelBrowser $client): string
    {
        self::assertTrue($client->getResponse()->isRedirection(), 'Nach dem Absenden geht es zurück zum Schritt');

        return $client->followRedirect()->filter('body')->text();
    }

    private static function givenMeteredItem(KernelBrowser $client): CostItem
    {
        $item = self::givenItem($client);
        self::measuring($client, $item->number(), self::key(true)->id(), 'm3');

        return self::found();
    }

    /**
     * @return array<string, string>
     */
    private static function consumptions(string $value): array
    {
        $property = self::propertyByName();
        self::assertNotNull($property);
        $units = $property->units();
        self::assertNotSame([], $units, 'Das Objekt hat keine Einheit.');

        return [$units[0]->id() => $value];
    }

    private static function firstUnitValue(): CostItemYearUnit
    {
        $year = self::found()->years()->forYear(2026);
        self::assertNotNull($year);
        self::assertNotSame([], $year->units(), 'Es wurde kein Verbrauch gespeichert.');

        return $year->units()[0];
    }

    private static function editor(): KernelBrowser
    {
        return self::signedInWith([FinancePermissions::VIEW, FinancePermissions::EDIT, FinancePermissions::DELETE]);
    }

    private static function givenItem(KernelBrowser $client): CostItem
    {
        $property = self::givenProperty();

        self::post($client, '/finanzen/kosten/neu', '/finanzen/kosten/neu', [
            'propertyId' => $property->id(),
            'kindId' => self::kind()->id(),
            'keyId' => self::key()->id(),
        ]);

        return self::found();
    }

    /** Eine Position ohne Ablauf — fuer die Pruefungen am Anwendungsfall. */
    private static function plainItem(bool $metered): CostItem
    {
        $item = new CostItem(
            self::items()->nextNumber(),
            self::givenProperty()->id(),
            self::kind(),
            self::key($metered),
        );
        self::items()->save($item);

        return $item;
    }

    /** Ein Jahr mit einer erfassten Menge — der Fall, um den es geht. */
    private static function givenMeteredYear(): CostItemYear
    {
        $item = self::plainItem(true);
        $item->measureIn(UnitOfMeasure::CubicMetre);
        $year = new CostItemYear($item, 2026, Money::fromCents(48000), EntryMode::Total);
        self::items()->save($item);
        self::quantities()->meter($year, [
            self::ownUnit()->id() => ['consumption' => '10,000', 'amount' => null],
        ]);

        return $year;
    }

    private static function ownUnit(): Unit
    {
        $units = self::givenProperty()->units();
        self::assertNotSame([], $units);

        return $units[0];
    }

    private static function givenOtherProperty(): Property
    {
        return self::givenOtherUnit()->property();
    }

    /** Eine Einheit, die zu einem anderen Objekt gehoert. */
    private static function givenOtherUnit(): Unit
    {
        $id = self::entityManager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::OTHER_PROPERTY],
        );

        if (\is_string($id)) {
            $found = self::properties()->byId($id);
            self::assertInstanceOf(Property::class, $found);
            $units = $found->units();
            self::assertNotSame([], $units);

            return $units[0];
        }

        $property = new Property(
            self::properties()->nextNumber(),
            self::OTHER_PROPERTY,
            ManagementModes::of([ManagementMode::Rental]),
        );
        $unit = new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $unit;
    }

    private static function save(): SaveCostItem
    {
        $save = self::getContainer()->get(SaveCostItem::class);
        self::assertInstanceOf(SaveCostItem::class, $save);

        return $save;
    }

    private static function record(): RecordAmounts
    {
        $record = self::getContainer()->get(RecordAmounts::class);
        self::assertInstanceOf(RecordAmounts::class, $record);

        return $record;
    }

    private static function quantities(): RecordQuantities
    {
        $quantities = self::getContainer()->get(RecordQuantities::class);
        self::assertInstanceOf(RecordQuantities::class, $quantities);

        return $quantities;
    }

    private static function givenProperty(): Property
    {
        $existing = self::propertyByName();

        if (null !== $existing) {
            return $existing;
        }

        $property = new Property(
            self::properties()->nextNumber(),
            self::PROPERTY,
            ManagementModes::of([ManagementMode::Rental]),
        );
        new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $property;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function post(KernelBrowser $client, string $url, string $page, array $fields): void
    {
        $crawler = $client->request('GET', $page);
        $token = $crawler->filter('main input[name="_token"]');
        self::assertGreaterThan(0, $token->count(), 'Kein Formular auf '.$page);

        $client->request('POST', $url, [...$fields, '_token' => (string) $token->first()->attr('value')]);
    }

    private static function found(): CostItem
    {
        $all = self::all();
        self::assertNotSame([], $all, 'Es wurde keine Kostenposition angelegt.');

        return $all[0];
    }

    /**
     * @return list<CostItem>
     */
    private static function all(): array
    {
        self::entityManager()->clear();
        $property = self::propertyByName();

        if (null === $property) {
            return [];
        }

        return self::items()->matching(
            CostItemFilter::of($property->id(), null, null, null),
            Page::of(1, 50),
            Sort::by('nummer'),
        );
    }

    private static function kind(): CostKind
    {
        foreach (self::kinds()->all() as $kind) {
            if ('Grundsteuer' === $kind->name()) {
                return $kind;
            }
        }

        self::fail('Die Grundsteuer fehlt im Katalog.');
    }

    private static function key(bool $metered = false): DistributionKey
    {
        foreach (self::keys()->all() as $key) {
            if ($key->isSystem() && $key->kind()->isMetered() === $metered) {
                return $key;
            }
        }

        self::fail('Es gibt keinen passenden Systemschlüssel.');
    }

    private static function propertyByName(): ?Property
    {
        $id = self::entityManager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::PROPERTY],
        );

        return \is_string($id) ? self::properties()->byId($id) : null;
    }

    private static function items(): CostItemRepository
    {
        $repository = self::getContainer()->get(CostItemRepository::class);
        self::assertInstanceOf(CostItemRepository::class, $repository);

        return $repository;
    }

    private static function kinds(): CostKindRepository
    {
        $repository = self::getContainer()->get(CostKindRepository::class);
        self::assertInstanceOf(CostKindRepository::class, $repository);

        return $repository;
    }

    private static function keys(): DistributionKeyRepository
    {
        $repository = self::getContainer()->get(DistributionKeyRepository::class);
        self::assertInstanceOf(DistributionKeyRepository::class, $repository);

        return $repository;
    }

    private static function properties(): PropertyRepository
    {
        $repository = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $repository);

        return $repository;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private static function cleanUp(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $connection = self::entityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM finance_cost_item WHERE property_id IN (SELECT id FROM property WHERE name = ?)',
            [self::PROPERTY],
        );
        $connection->executeStatement('DELETE FROM property WHERE name IN (?, ?)', [self::PROPERTY, self::OTHER_PROPERTY]);
    }
}
