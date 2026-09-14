<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\CheckCorrections;
use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Application\CorrectStatement;
use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Application\MeasuresWithoutCosts;
use App\Module\Billing\Application\ReleaseStatement;
use App\Module\Billing\Domain\ProposedAdvance;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementIsReleased;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Billing\UserInterface\Pdf\StatementLetter;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Property\Domain\FiscalYear;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Eine Abrechnung von der Berechnung bis zum PDF.
 *
 * Der Test, an dem das Modul haengt. Er prueft die drei Zusicherungen, die
 * eine Abrechnung ueberhaupt erst brauchbar machen: die Summe der Anteile ist
 * exakt der Gesamtbetrag, ein Mieter traegt nur seine Tage, und dasselbe
 * Dokument ergibt zweimal dieselben Bytes.
 */
final class BillingStatementTest extends WebTestCase
{
    use BuildsABillableProperty;

    protected function tearDown(): void
    {
        self::removeTheProperty();

        parent::tearDown();
    }

    /**
     * Die Zusicherung, an der alles haengt: es geht auf.
     *
     * Zwei Einheiten, 78,40 und 64,20 Quadratmeter, 1.240,50 Euro Grundsteuer.
     * Die Anteile muessen zusammen wieder 1.240,50 ergeben — auf den Cent.
     */
    public function testTheSharesAddUpToTheTotal(): void
    {
        $statement = $this->aStatementFor(2026);
        $proposal = self::compose()->of($statement, ...array_values(self::everything($statement)));

        $shared = Money::zero();

        foreach ($proposal->documents as $document) {
            if (StatementKind::HouseMoney === $document->kind) {
                $shared = $shared->plus($document->costs());
            }
        }

        self::assertSame(172050, $shared->cents(), '1.240,50 + 480,00 gehen auf');
    }

    /**
     * Nach Miteigentumsanteilen — der haeufigste Schluessel einer Hausgeld-
     * abrechnung.
     *
     * Lange von keinem Test beruehrt, und genau darin steckte ein Fehler: die
     * Einheitenkurzform trug ihren Anteil als „250/1000", und daraus laesst
     * sich nicht verteilen. Jede Abrechnung mit diesem Schluessel lief in
     * einen Serverfehler.
     */
    public function testItDistributesByCoOwnershipShares(): void
    {
        $statement = $this->aStatementFor(2026);
        self::alsoCostsByMea(Money::fromCents(90000));

        $proposal = self::compose()->of($statement, ...array_values(self::everything($statement)));
        $shared = Money::zero();

        foreach ($proposal->documents as $document) {
            if (StatementKind::HouseMoney !== $document->kind) {
                continue;
            }

            foreach ($document->lines as $line) {
                if ('Prüfverwaltung' === $line->costKind) {
                    self::assertSame('450', $line->distribution->shareTotal(), 'Der Nenner sind die gehaltenen Anteile');
                    $shared = $shared->plus($line->amount);
                }
            }
        }

        self::assertSame(90000, $shared->cents(), '900,00 € gehen restlos auf die Anteile');
    }

    /**
     * Eine Sonderumlage steht auf der Hausgeldabrechnung — und nur dort.
     *
     * Sie schuldet der Eigentuemer, wie das Hausgeld. Im Zahlungsschritt stand
     * sie zur Wahl und war angehakt; auf dem Blatt fehlte sie trotzdem, weil
     * dort die Zahlungsart mit der Abrechnungsart verglichen wurde und
     * „Sonderumlage" auf keine von beiden passte. Angeboten und dann still
     * fallengelassen ist schlimmer als gar nicht angeboten.
     */
    public function testASpecialLevyBelongsToTheOwnerAndNotToTheTenant(): void
    {
        $statement = $this->aStatementFor(2026);
        self::alsoLevied(0, Money::fromCents(166667), '2026-10-01');

        $proposal = self::compose()->of($statement, ...array_values(self::everything($statement)));
        $owners = [];
        $tenants = [];

        // Am Betrag und nicht am Tag: am Ersten des Monats ist auch Hausgeld
        // faellig, und dann faende sich der Tag auch ohne die Sonderumlage.
        foreach ($proposal->documents as $document) {
            $amounts = array_map(
                static fn (ProposedAdvance $advance): int => $advance->expected->cents(),
                $document->advances,
            );

            if (StatementKind::HouseMoney === $document->kind) {
                $owners = [...$owners, ...$amounts];

                continue;
            }

            $tenants = [...$tenants, ...$amounts];
        }

        self::assertContains(166667, $owners, 'Die Sonderumlage steht beim Eigentümer');
        self::assertNotContains(166667, $tenants, 'Und nicht beim Mieter');
    }

    /**
     * Eine Sonderumlage zur Ruecklage steht nicht in der Ergebnisrechnung.
     *
     * Sie ist kein Vorschuss auf die Kosten des Jahres: das Geld liegt auf
     * der Ruecklage, bis eine Rechnung daraus bezahlt wird. Stuende sie oben
     * bei den Vorauszahlungen, entstuende ein Guthaben, dem nichts
     * gegenuebersteht — und im Jahr der Entnahme eine Nachzahlung, die
     * niemand erwartet. Sie steht stattdessen im Ruecklagenauszug.
     */
    public function testALevyForTheReserveStaysOutOfTheResult(): void
    {
        $statement = $this->aStatementFor(2026);
        self::alsoLevied(0, Money::fromCents(166667), '2026-10-01', AdvanceKind::ReserveLevy);

        $proposal = self::compose()->of($statement, ...array_values(self::everything($statement)));
        $amounts = [];

        foreach ($proposal->documents as $document) {
            foreach ($document->advances as $advance) {
                $amounts[] = $advance->expected->cents();
            }
        }

        self::assertNotContains(166667, $amounts, 'Nicht bei den Vorauszahlungen');
        self::assertSame(166667, $proposal->reserve()->specialLevies()->cents(), 'Sondern im Rücklagenauszug');
    }

    /**
     * Der Auszug wird mit der Freigabe eingefroren.
     *
     * Ein Blatt, dessen Ruecklagenstand sich mit der naechsten Entnahme
     * aendert, stuende rueckwirkend anders in der Hand des Empfaengers.
     */
    public function testTheReserveSummaryIsFrozenWithTheRelease(): void
    {
        $statement = $this->aStatementFor(2026);
        self::alsoLevied(0, Money::fromCents(166667), '2026-10-01', AdvanceKind::ReserveLevy);

        $chosen = self::everything($statement);
        self::selection()->keep($statement, $chosen['costs'], $chosen['payments']);
        self::release()->release($statement, new DateTimeImmutable('2027-03-01'));

        self::assertSame(166667, $statement->reserve()->specialLevies()->cents());

        // Was danach passiert, aendert das Blatt nicht mehr.
        self::alsoLevied(1, Money::fromCents(50000), '2026-11-01', AdvanceKind::ReserveLevy);

        self::assertSame(166667, $statement->reserve()->specialLevies()->cents(), 'Eingefroren');
    }

    /**
     * Eine Sonderumlage ohne Kosten im Jahr wird angesagt.
     *
     * Sie ist ein Vorschuss auf die Kosten ihrer Massnahme. Fallen die in
     * einem anderen Jahr an, entsteht hier ein Guthaben, das keines ist — und
     * zwei Jahre spaeter eine Nachzahlung, die niemand erwartet. Ein Hinweis
     * und keine Sperre.
     */
    public function testALevyWithoutCostsInTheYearIsPointedOut(): void
    {
        $statement = $this->aStatementFor(2026);
        self::alsoLevied(0, Money::fromCents(166667), '2026-10-01', reference: 'BU-29001-2026-4');

        $missing = self::getContainer()->get(MeasuresWithoutCosts::class);
        self::assertInstanceOf(MeasuresWithoutCosts::class, $missing);
        $offered = self::compose()->offered($statement);

        self::assertSame(['BU-29001-2026-4'], $missing->among($offered['payments'], 2026), 'Keine Kosten, also ein Hinweis');

        // Kosten derselben Massnahme, aber in einem anderen Jahr: genau der
        // Fall, den der Hinweis meint — eingesammelt 2026, gebaut 2028.
        self::alsoCostsForTheMeasure('BU-29001-2026-4', Money::fromCents(300000), 2028);

        self::assertSame(
            ['BU-29001-2026-4'],
            $missing->among(self::compose()->offered($statement)['payments'], 2026),
            'Kosten in einem anderen Jahr helfen diesem Jahr nicht',
        );

        // Und im Jahr selbst — dann verschwindet der Hinweis.
        self::alsoCostsForTheMeasure('BU-29001-2026-4', Money::fromCents(120000), 2026);

        self::assertSame([], $missing->among(self::compose()->offered($statement)['payments'], 2026));
    }

    /** Fehlt eine Angabe, bleibt die Freigabe gesperrt. */
    public function testAMissingFigureBlocksTheRelease(): void
    {
        $statement = $this->aStatementFor(2026, withArea: false);
        $proposal = self::compose()->of($statement, ...array_values(self::everything($statement)));

        self::assertFalse($proposal->isComplete());
        self::assertNotSame([], $proposal->missing);
    }

    /**
     * Zweimal erzeugt heisst byteweise gleich.
     *
     * FPDF setzt sonst `time()` als Erstellungsdatum, und schon die zweite
     * Sekunde ergaebe eine andere Datei.
     */
    public function testTwoRunsGiveTheSameBytes(): void
    {
        $statement = $this->aStatementFor(2026);
        $chosen = self::everything($statement);
        self::selection()->keep($statement, $chosen['costs'], $chosen['payments']);
        self::release()->release($statement, new DateTimeImmutable('2027-03-01'));

        $document = $statement->documents()[0] ?? null;
        self::assertNotNull($document);

        $letter = self::letter();
        $first = $letter->of($statement, $document);
        $second = $letter->of($statement, $document);

        self::assertSame($first, $second, 'Dasselbe Dokument, dieselben Bytes');
        self::assertStringStartsWith('%PDF-', $first);
    }

    /** Und was eingegangen ist, steht danach fest. */
    public function testAReleasedDocumentDoesNotFollowLaterChanges(): void
    {
        $statement = $this->aStatementFor(2026);
        $chosen = self::everything($statement);
        self::selection()->keep($statement, $chosen['costs'], $chosen['payments']);
        self::release()->release($statement, new DateTimeImmutable('2027-03-01'));

        $document = $statement->documents()[0] ?? null;
        self::assertNotNull($document);
        $before = $document->balance()->cents();

        self::raiseTheCosts();

        self::assertSame(
            $before,
            $document->balance()->cents(),
            'Das zugestellte Schreiben ändert sich nicht mit',
        );
    }

    /**
     * Das Wirtschaftsjahr eines Laufs bleibt, was es war.
     *
     * Die Regel steht am Objekt und darf sich aendern: eine Verwaltung stellt
     * vom Kalenderjahr auf den 1. Juli um. Setzte ein vorhandener Lauf seinen
     * Zeitraum danach neu zusammen, rechnete er ueber Juli 2026 bis Juni 2027
     * — mit anderen Mietzeiten, anderen Vorauszahlungen und anderen
     * Zeitanteilen. Die Korrektur korrigierte dann nicht mehr ihr Original.
     */
    public function testChangingTheFiscalYearDoesNotMoveAnExistingRun(): void
    {
        $statement = $this->aStatementFor(2026);
        $chosen = self::everything($statement);
        self::selection()->keep($statement, $chosen['costs'], $chosen['payments']);
        self::release()->release($statement, new DateTimeImmutable('2027-03-01'));

        self::moveTheFiscalYearToJuly();

        self::assertSame('2026-01-01', $statement->period()->from()->format('Y-m-d'));
        self::assertSame('2026-12-31', $statement->period()->to()->format('Y-m-d'));
        self::assertSame(
            [],
            self::checks()->changed($statement),
            'Ein verschobenes Wirtschaftsjahr macht aus einer richtigen Abrechnung keine falsche',
        );
    }

    /**
     * Korrigiert wird der aktuelle Stand, nicht der angeklickte.
     *
     * Der Knopf kann von einer laengst ueberholten Iteration kommen — aus
     * einem offenen Tab, aus dem Zurueckknopf des Browsers. Wuerde die
     * Korrektur von dort abgeleitet, entstuende dieselbe Iteration zweimal
     * und die zweite liefe in den eindeutigen Index.
     */
    public function testACorrectionAlwaysFollowsTheLatestIteration(): void
    {
        $original = $this->aReleasedStatement();

        $first = self::corrections()->of($original);
        self::release()->release($first, new DateTimeImmutable('2027-04-01'));

        // Noch einmal vom *Original* aus — so, wie es ein alter Tab täte.
        $second = self::corrections()->of($original);

        self::assertSame(3, $second->iteration(), 'Die dritte Iteration und nicht noch einmal die zweite');
        self::assertSame($first->id(), $second->correctsId(), 'Korrigiert wird die jüngste Iteration');
    }

    /** Und zwei offene Korrekturen derselben Nummer gibt es nicht. */
    public function testThereIsOnlyOneOpenCorrectionPerStatement(): void
    {
        $original = $this->aReleasedStatement();
        $underway = self::corrections()->of($original);

        self::assertSame($underway->id(), self::corrections()->openFor($original->number())?->id());

        $this->expectException(StatementIsReleased::class);
        self::corrections()->of($original);
    }

    private function aReleasedStatement(): Statement
    {
        $statement = $this->aStatementFor(2026);
        $chosen = self::everything($statement);
        self::selection()->keep($statement, $chosen['costs'], $chosen['payments']);
        self::release()->release($statement, new DateTimeImmutable('2027-03-01'));

        return $statement;
    }

    private function aStatementFor(int $year, bool $withArea = true): Statement
    {
        self::bootKernel();
        self::buildTheProperty($withArea);

        $statements = self::statements();
        $statement = new Statement($statements->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear($year));
        $statements->save($statement);

        return $statement;
    }

    /**
     * @return array{costs: list<string>, payments: list<string>}
     */
    private static function everything(Statement $statement): array
    {
        $offered = self::compose()->offered($statement);

        return [
            'costs' => array_map(static fn (object $cost): string => $cost->costYearId, $offered['costs']),
            'payments' => array_map(static fn (object $paid): string => $paid->paymentId, $offered['payments']),
        ];
    }

    /** Vom Kalenderjahr auf den 1. Juli — nach der Freigabe. */
    private static function moveTheFiscalYearToJuly(): void
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);
        $property = $properties->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        $property->accountsAs($property->accounting()->beginningOn(FiscalYear::beginningOn(1, 7)));
        $properties->save($property);
    }

    private static function corrections(): CorrectStatement
    {
        $found = self::getContainer()->get(CorrectStatement::class);
        self::assertInstanceOf(CorrectStatement::class, $found);

        return $found;
    }

    private static function checks(): CheckCorrections
    {
        $found = self::getContainer()->get(CheckCorrections::class);
        self::assertInstanceOf(CheckCorrections::class, $found);

        return $found;
    }

    private static function statements(): StatementRepository
    {
        $found = self::getContainer()->get(StatementRepository::class);
        self::assertInstanceOf(StatementRepository::class, $found);

        return $found;
    }

    private static function compose(): ComposeStatement
    {
        $found = self::getContainer()->get(ComposeStatement::class);
        self::assertInstanceOf(ComposeStatement::class, $found);

        return $found;
    }

    private static function selection(): DraftSelection
    {
        $found = self::getContainer()->get(DraftSelection::class);
        self::assertInstanceOf(DraftSelection::class, $found);

        return $found;
    }

    private static function release(): ReleaseStatement
    {
        $found = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $found);

        return $found;
    }

    private static function letter(): StatementLetter
    {
        $found = self::getContainer()->get(StatementLetter::class);
        self::assertInstanceOf(StatementLetter::class, $found);

        return $found;
    }
}
