<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Application\CorrectStatement;
use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Application\ReleaseStatement;
use App\Module\Billing\Domain\Proposal;
use App\Module\Billing\Domain\ProposedAdvance;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Finance\Application\DueAdvances;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Tenancy\Domain\HouseholdStep;
use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\Term;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der unterjaehrige Mieterwechsel.
 *
 * Der Fall, an dem eine Abrechnung am ehesten still falsch wird: zwei Mieter
 * in einem Jahr, eine Einheit, ein Gesamtbetrag. Wer jeden Zeitraum fuer sich
 * rundet, verteilt am Ende einen Cent mehr oder weniger, als es gab — und es
 * faellt niemandem auf, weil beide Zahlen fuer sich plausibel aussehen.
 *
 * Die Zusicherung heisst darum nicht „ungefaehr richtig", sondern: **die
 * Anteile der Mieter ergeben zusammen genau den Anteil der Einheit.** Der
 * Eigentuemer bekommt denselben Posten fuer das ganze Jahr; seine Zeile ist
 * der Massstab, gegen den gerechnet wird.
 *
 * Einheit 1 ist 2026 vermietet, Einheit 2 nicht. Das Objekt wird als WEG
 * verwaltet, also entstehen beide Arten: Hausgeld an den Eigentuemer,
 * Nebenkosten an jeden Mieter.
 */
final class BillingTenantChangeTest extends WebTestCase
{
    use BuildsABillableProperty;

    /** Der Zahlenraum, aus dem sich die Mieter dieses Tests bedienen. */
    private const int FIRST_TENANT = 99002;

    /** Ein Name je Mietverhaeltnis, in der Reihenfolge des Jahres. */
    private const array NAMES = [
        ['Erste', 'Mieterin'],
        ['Zweiter', 'Mieter'],
        ['Dritte', 'Mieterin'],
    ];

    /** Der Lauf, den {@see documentsWithTenancies} zuletzt angelegt hat. */
    private static ?Statement $draft = null;

    protected function tearDown(): void
    {
        self::removeTheTenancies();
        self::removeTheProperty();
        self::$draft = null;

        parent::tearDown();
    }

    /**
     * Nahtloser Wechsel: die beiden zusammen ergeben genau das Ganze.
     *
     * Auf den Cent, fuer jede Kostenart einzeln. Ein halbes Jahr und das
     * andere halbe ergaben einmal 682,03 statt 682,02 Euro.
     *
     * Und das an jedem Monatsende, nicht nur an einem: ein Verfahren, das
     * jeden Zeitraum fuer sich rundet, geht bei manchen Stichtagen zufaellig
     * auf und bei anderen nicht. Wer nur den 30. Juni prueft, prueft das
     * Verfahren nicht, sondern seinen Glueckstag.
     */
    #[DataProvider('lastDaysOfMonths')]
    public function testTwoTenantsTogetherBearExactlyTheWholeUnit(string $lastDay, string $nextDay): void
    {
        $documents = self::documentsWithTenancies([['2026-01-01', $lastDay], [$nextDay, '2026-12-31']]);
        $owner = self::theOwnersLetter($documents);
        $tenants = self::theTenantLetters($documents);

        self::assertCount(2, $tenants, 'Zwei Mietverhältnisse, zwei Abrechnungen');
        self::assertTheyAddUp($owner, $tenants);
    }

    /**
     * Drei Mieter in einem Jahr — und es geht immer noch auf.
     *
     * Zwei Zeitraeume koennen gar nicht danebenliegen: was der eine
     * aufrundet, rundet der andere ab. Erst ab dreien kann ein Verfahren, das
     * jeden Abschnitt fuer sich rundet, einen Cent verlieren — Januar,
     * Februar bis Maerz, April bis Dezember ist so ein Fall.
     *
     * Der Test steht deshalb hier und nicht als vierte Zeile im vorigen: er
     * prueft etwas, das der vorige gar nicht pruefen kann.
     */
    public function testThreeTenantsInAYearStillAddUp(): void
    {
        $documents = self::documentsWithTenancies([
            ['2026-01-01', '2026-01-31'],
            ['2026-02-01', '2026-03-31'],
            ['2026-04-01', '2026-12-31'],
        ]);
        $tenants = self::theTenantLetters($documents);

        self::assertCount(3, $tenants, 'Drei Mietverhältnisse, drei Abrechnungen');
        self::assertTheyAddUp(self::theOwnersLetter($documents), $tenants);
    }

    /**
     * Jedes Monatsende des Jahres als Wechseltag.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function lastDaysOfMonths(): iterable
    {
        foreach (range(1, 11) as $month) {
            $last = new DateTimeImmutable(\sprintf('2026-%02d-01', $month + 1));
            $last = $last->modify('-1 day');

            yield $last->format('F') => [$last->format('Y-m-d'), $last->modify('+1 day')->format('Y-m-d')];
        }
    }

    /** Und jeder traegt die Tage, die er da war — 181 und 184. */
    public function testEachTenantBearsOnlyTheirOwnDays(): void
    {
        $tenants = self::theTenantLetters(self::documentsWithTenancies([['2026-01-01', '2026-06-30'], ['2026-07-01', '2026-12-31']]));

        self::assertSame('2026-01-01', self::letterAt($tenants, 0)->from->format('Y-m-d'));
        self::assertSame('2026-06-30', self::letterAt($tenants, 0)->to->format('Y-m-d'));
        self::assertSame('2026-07-01', self::letterAt($tenants, 1)->from->format('Y-m-d'));
        self::assertSame('2026-12-31', self::letterAt($tenants, 1)->to->format('Y-m-d'));

        self::assertSame('Erste Mieterin', self::letterAt($tenants, 0)->recipientLabel);
        self::assertSame('Zweiter Mieter', self::letterAt($tenants, 1)->recipientLabel);

        foreach ([[self::letterAt($tenants, 0), 181], [self::letterAt($tenants, 1), 184]] as [$document, $days]) {
            foreach ($document->lines as $line) {
                self::assertSame($days, $line->distribution->daysOf(), $line->costKind);
                self::assertSame(365, $line->distribution->daysTotal(), $line->costKind);
            }
        }
    }

    /**
     * Steht die Wohnung dazwischen leer, traegt die Zeit niemand.
     *
     * Der Juni gehoert keinem Mietverhaeltnis. Er darf weder auf einen der
     * beiden Mieter fallen noch verschwinden: die Abrechnung des Eigentuemers
     * bleibt unveraendert, und der Unterschied ist genau der Leerstand.
     */
    public function testAMonthOfVacancyFallsToNeitherTenant(): void
    {
        $documents = self::documentsWithTenancies([['2026-01-01', '2026-05-31'], ['2026-07-01', '2026-12-31']]);
        $owner = self::theOwnersLetter($documents);
        $tenants = self::theTenantLetters($documents);

        foreach (self::costKindsIn($owner) as $costKind) {
            $whole = self::amountOf($owner, $costKind)->cents();
            $borne = self::amountOf(self::letterAt($tenants, 0), $costKind)->plus(self::amountOf(self::letterAt($tenants, 1), $costKind))->cents();
            $empty = $whole - $borne;

            self::assertGreaterThan(0, $empty, $costKind.': der Leerstand fällt nicht auf die Mieter');
            self::assertLessThanOrEqual(
                1,
                abs($empty - intdiv($whole * 30, 365)),
                $costKind.': der Leerstand ist genau der Juni',
            );
        }

        // Und fuer den leeren Monat war auch nichts faellig: elf
        // Vorauszahlungen statt zwoelf.
        self::assertCount(5, self::letterAt($tenants, 0)->advances, 'Januar bis Mai');
        self::assertCount(6, self::letterAt($tenants, 1)->advances, 'Juli bis Dezember');
    }

    /**
     * Und die Freigabe friert genau das ein, was in der Vorschau stand.
     *
     * Der Mieterwechsel ist der Fall, in dem zwei Schreiben einer Einheit
     * dieselbe Art tragen. Wenn irgendwo nach dem Empfaenger statt nach dem
     * Zeitraum zugeordnet wird, faellt es hier auf: dann traegt eines der
     * beiden die Zahlen des anderen.
     */
    public function testTheReleaseFreezesEachPeriodOnItsOwn(): void
    {
        $periods = [['2026-01-01', '2026-06-30'], ['2026-07-01', '2026-12-31']];
        $proposed = self::theTenantLetters(self::documentsWithTenancies($periods));
        $statement = self::theDraft();

        self::keepEverything($statement);
        $release = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $release);
        $release->release($statement, new DateTimeImmutable('2027-03-01'));

        $released = array_values(array_filter(
            $statement->documents(),
            static fn (StatementDocument $d): bool => StatementKind::OperatingCosts === $d->kind() && 1 === $d->unitNumber(),
        ));
        usort(
            $released,
            static fn (StatementDocument $one, StatementDocument $other): int => $one->periodFrom() <=> $other->periodFrom(),
        );

        self::assertCount(2, $released);

        foreach ([0, 1] as $at) {
            self::assertSame(
                self::letterAt($proposed, $at)->from->format('Y-m-d'),
                self::releasedAt($released, $at)->periodFrom()->format('Y-m-d'),
            );
            self::assertSame(self::letterAt($proposed, $at)->recipientLabel, self::releasedAt($released, $at)->recipient()->label());
            self::assertTrue(
                self::releasedAt($released, $at)->balance()->equals(self::letterAt($proposed, $at)->balance()),
                'Das eingefrorene Ergebnis ist das geprüfte',
            );
        }
    }

    /**
     * Ein Empfaenger, den es nicht mehr gibt, wird ausgeglichen.
     *
     * Ein Mieter war ab dem 1. Januar abgerechnet; danach stellt sich heraus,
     * dass der Vertrag erst am 1. Februar begann. Der heutige Vorschlag kennt
     * nur noch den neuen Zeitraum — das Januar-Schreiben stuende ohne
     * Ausgleich in der Welt, und der Februar-Betrag kaeme in voller Hoehe
     * obendrauf. Zweimal dieselbe Forderung, einmal zu Unrecht.
     *
     * Die Korrektur muss darum beides enthalten: das neue Schreiben und eine
     * Gutschrift ueber genau das, was vorher gefordert wurde.
     */
    public function testARecipientThatFellAwayIsBalancedOut(): void
    {
        self::documentsWithTenancies([['2026-01-01', '2026-12-31']]);
        $original = self::theDraft();
        self::keepEverything($original);
        self::release()->release($original, new DateTimeImmutable('2027-03-01'));

        $wasBilled = self::releasedAt(self::tenantDocumentsOf($original), 0);
        $before = $wasBilled->balance();

        self::correctTheStartTo('2026-02-01');
        $correction = self::corrections()->of($original);
        self::release()->release($correction, new DateTimeImmutable('2027-04-01'));

        $documents = self::tenantDocumentsOf($correction);
        self::assertCount(2, $documents, 'Das neue Schreiben und der Ausgleich für das alte');

        $balance = self::releasedAt($documents, 0);
        self::assertSame('2026-01-01', $balance->periodFrom()->format('Y-m-d'));
        self::assertTrue($balance->outcome()->result()->isZero(), 'Für den Januar-Zeitraum fällt heute nichts mehr an');
        self::assertTrue(
            $balance->balance()->equals(Money::zero()->minus($before)),
            'Gutgeschrieben wird genau, was gefordert wurde',
        );

        $now = self::releasedAt($documents, 1);
        self::assertSame('2026-02-01', $now->periodFrom()->format('Y-m-d'));

        // Und unter dem Strich steht über beide Läufe, was heute stimmt.
        self::assertTrue(
            $before->plus($balance->balance())->plus($now->balance())->equals($now->outcome()->result()),
            'Über alle Iterationen zusammen ist gefordert, was heute richtig ist',
        );
    }

    /**
     * Leerstand traegt der Eigentuemer — nicht die Nachbarn.
     *
     * Die Wohnung ist bis Ende Juni vermietet und steht danach leer: kein
     * Mieter, nicht selbst bewohnt. Fuer den Personenschluessel zaehlen dann
     * die Tage der Mietzeit mit der Zahl des Mietvertrags und die uebrigen
     * mit der Zahl, die an der Einheit steht.
     *
     * Stuende der Leerstand mit null da, verteilte sich der Anteil der
     * Wohnung still auf die anderen Einheiten: der Leerstand des einen waere
     * die Rechnung des anderen.
     */
    public function testAVacantHalfYearIsBorneByTheOwner(): void
    {
        self::buildTheProperty();
        self::alsoCostsByPerson(Money::fromCents(60000));

        $unit = self::unitIds()[0] ?? '';
        self::aTenancy($unit, '2026-01-01', '2026-06-30', self::aTenant(self::FIRST_TENANT, 'Erste', 'Mieterin'), true);
        // Zwei Personen im Mietvertrag, zwei an der Einheit: so haengt das
        // Ergebnis nur am Leerstand und nicht an einem Zahlenwechsel.
        self::housesItself(0, 2, '2026-07-01');
        self::housesItself(1, 2);
        self::theOperatingCostPayments($unit);

        $proposal = self::proposalOfAFreshRun();

        self::assertSame([], $proposal->missing, 'Der Leerstand ist keine fehlende Angabe');

        $owners = array_values(array_filter(
            $proposal->documents,
            static fn (ProposedDocument $d): bool => StatementKind::HouseMoney === $d->kind,
        ));

        self::assertCount(2, $owners);
        self::assertTrue(
            self::amountOf(self::letterAt($owners, 0), 'Prüfmüll')
                ->plus(self::amountOf(self::letterAt($owners, 1), 'Prüfmüll'))
                ->equals(Money::fromCents(60000)),
            'Zusammen genau der Betrag — der Leerstand fällt niemandem sonst zu',
        );
        self::assertSame(
            30000,
            self::amountOf(self::letterAt($owners, 0), 'Prüfmüll')->cents(),
            'Zwei Personen das ganze Jahr, egal ob gemietet oder leer',
        );
    }

    /**
     * Die Vorauszahlungen folgen dem Mietverhaeltnis.
     *
     * Der BGH verlangt den Abzug der **tatsaechlich geleisteten**
     * Vorauszahlungen. Die des Nachmieters auf der Abrechnung des Vormieters
     * waeren nicht nur zu viel — sie waeren die eines Fremden.
     */
    public function testTheAdvancesFollowTheTenancy(): void
    {
        $tenants = self::theTenantLetters(self::documentsWithTenancies([['2026-01-01', '2026-06-30'], ['2026-07-01', '2026-12-31']]));

        self::assertCount(6, self::letterAt($tenants, 0)->advances, 'Januar bis Juni');
        self::assertCount(6, self::letterAt($tenants, 1)->advances, 'Juli bis Dezember');

        foreach ($tenants as $document) {
            foreach ($document->advances as $advance) {
                self::assertInstanceOf(ProposedAdvance::class, $advance);
                self::assertGreaterThanOrEqual($document->from, $advance->dueOn);
                self::assertLessThanOrEqual($document->to, $advance->dueOn);
            }
        }

        self::assertSame(
            [],
            array_intersect(self::dueDaysOf(self::letterAt($tenants, 0)), self::dueDaysOf(self::letterAt($tenants, 1))),
            'Kein Tag steht auf beiden Schreiben',
        );
    }

    /**
     * Die Zusicherung: Summe der Mieteranteile = Anteil der Einheit.
     *
     * Je Kostenart einzeln und auf den Cent. Der Eigentuemer bekommt jede
     * Position fuer das ganze Jahr, also ist seine Zeile der Massstab.
     *
     * @param list<ProposedDocument> $tenants
     */
    private static function assertTheyAddUp(ProposedDocument $owner, array $tenants): void
    {
        foreach (self::costKindsIn($owner) as $costKind) {
            $borne = Money::zero();

            foreach ($tenants as $tenant) {
                $borne = $borne->plus(self::amountOf($tenant, $costKind));
            }

            self::assertTrue(
                $borne->equals(self::amountOf($owner, $costKind)),
                \sprintf(
                    '%s: die Mieter tragen zusammen %s, die Einheit trägt %s',
                    $costKind,
                    $borne->cents(),
                    self::amountOf($owner, $costKind)->cents(),
                ),
            );
        }
    }

    private static function theDraft(): Statement
    {
        $draft = self::$draft;
        self::assertInstanceOf(Statement::class, $draft);

        return $draft;
    }

    /** Alles aufnehmen, was zur Wahl steht — wie der Ablauf es vorbelegt. */
    private static function keepEverything(Statement $statement): void
    {
        $compose = self::getContainer()->get(ComposeStatement::class);
        self::assertInstanceOf(ComposeStatement::class, $compose);
        $selection = self::getContainer()->get(DraftSelection::class);
        self::assertInstanceOf(DraftSelection::class, $selection);
        $offered = $compose->offered($statement);

        $selection->keep(
            $statement,
            array_map(static fn (object $cost): string => $cost->costYearId, $offered['costs']),
            array_map(static fn (object $payment): string => $payment->paymentId, $offered['payments']),
        );
    }

    /**
     * Ein Lauf ueber das Objekt, nachdem die erste Einheit vermietet war.
     *
     * @param non-empty-list<array{string, string}> $periods Mietzeiten, zeitlich sortiert
     *
     * @return list<ProposedDocument>
     */
    private static function documentsWithTenancies(array $periods): array
    {
        self::buildTheProperty();

        $unit = self::unitIds()[0] ?? '';
        $last = \count($periods) - 1;

        foreach ($periods as $at => [$from, $to]) {
            $name = self::NAMES[$at] ?? ['Weiterer', 'Mieter'];
            self::aTenancy(
                $unit,
                $from,
                $to,
                self::aTenant(self::FIRST_TENANT + $at, $name[0], $name[1]),
                $at !== $last,
            );
        }

        self::theOperatingCostPayments($unit);

        return self::proposalOfAFreshRun()->documents;
    }

    /** Ein frischer Lauf ueber das Objekt, alles angehakt. */
    private static function proposalOfAFreshRun(): Proposal
    {
        $statements = self::getContainer()->get(StatementRepository::class);
        self::assertInstanceOf(StatementRepository::class, $statements);
        $statement = new Statement($statements->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear(2026));
        $statements->save($statement);
        self::$draft = $statement;

        $compose = self::getContainer()->get(ComposeStatement::class);
        self::assertInstanceOf(ComposeStatement::class, $compose);
        $offered = $compose->offered($statement);

        return $compose->of(
            $statement,
            array_map(static fn (object $cost): string => $cost->costYearId, $offered['costs']),
            array_map(static fn (object $payment): string => $payment->paymentId, $offered['payments']),
        );
    }

    /**
     * Das Schreiben an den Eigentuemer der ersten Einheit.
     *
     * @param list<ProposedDocument> $documents
     */
    private static function theOwnersLetter(array $documents): ProposedDocument
    {
        foreach ($documents as $document) {
            if (StatementKind::HouseMoney === $document->kind && 1 === $document->unitNumber) {
                return $document;
            }
        }

        self::fail('Der Eigentümer der ersten Einheit bekommt kein Schreiben.');
    }

    /**
     * Die Nebenkostenabrechnungen der ersten Einheit, zeitlich sortiert.
     *
     * @param list<ProposedDocument> $documents
     *
     * @return list<ProposedDocument>
     */
    private static function theTenantLetters(array $documents): array
    {
        $found = array_values(array_filter(
            $documents,
            static fn (ProposedDocument $d): bool => StatementKind::OperatingCosts === $d->kind && 1 === $d->unitNumber,
        ));
        usort($found, static fn (ProposedDocument $one, ProposedDocument $other): int => $one->from <=> $other->from);

        return $found;
    }

    /**
     * Das Schreiben an dieser Stelle — und die Absage, wenn es fehlt.
     *
     * Ein fehlendes Schreiben ist ein Befund und keine Ausnahme: der Test
     * soll dann sagen, dass eines fehlt, und nicht an einem undefinierten
     * Index sterben.
     *
     * @param list<ProposedDocument> $documents
     */
    private static function letterAt(array $documents, int $at): ProposedDocument
    {
        $document = $documents[$at] ?? null;
        self::assertInstanceOf(ProposedDocument::class, $document, 'Das '.($at + 1).'. Schreiben fehlt.');

        return $document;
    }

    /**
     * Dasselbe fuer die eingefrorenen Dokumente.
     *
     * @param list<StatementDocument> $documents
     */
    private static function releasedAt(array $documents, int $at): StatementDocument
    {
        $document = $documents[$at] ?? null;
        self::assertInstanceOf(StatementDocument::class, $document, 'Das '.($at + 1).'. Dokument fehlt.');

        return $document;
    }

    /** @return list<string> */
    private static function costKindsIn(ProposedDocument $document): array
    {
        return array_map(static fn (ProposedLine $line): string => $line->costKind, $document->lines);
    }

    private static function amountOf(ProposedDocument $document, string $costKind): Money
    {
        foreach ($document->lines as $line) {
            if ($line->costKind === $costKind) {
                return $line->amount;
            }
        }

        self::fail($costKind.' fehlt auf dem Schreiben an '.$document->recipientLabel.'.');
    }

    /** @return list<string> */
    private static function dueDaysOf(ProposedDocument $document): array
    {
        return array_map(
            static fn (ProposedAdvance $advance): string => $advance->dueOn->format('Y-m-d'),
            $document->advances,
        );
    }

    /**
     * Ein Mietverhaeltnis mit einer Betriebskostenvorauszahlung.
     *
     * Der Vormieter ist ausgezogen und damit **beendet**, nicht aktiv: eine
     * Einheit hat hoechstens ein laufendes Mietverhaeltnis, und die Datenbank
     * besteht darauf. Fuer die Abrechnung zaehlt das nicht — sie fragt nach
     * dem Jahr und bekommt jeden Abschnitt, der es beruehrt hat.
     */
    private static function aTenancy(
        string $unitId,
        string $from,
        string $to,
        Party $tenant,
        bool $movedOut,
    ): void {
        $tenancies = self::tenancies();
        $tenancy = new Tenancy($tenancies->nextNumber(), $unitId);
        new Tenant($tenancy, $tenant->id());
        new RentStep($tenancy, new DateTimeImmutable($from), Rent::of(
            Money::fromCents(80000),
            Money::fromCents(15000),
            Money::zero(),
            Money::zero(),
        ));
        // Mit Personenzahl: ohne sie waere der Personenschluessel fuer die
        // Mietzeit eine fehlende Angabe, und darum geht es hier nicht.
        new HouseholdStep($tenancy, new DateTimeImmutable($from), 2);
        $tenancy->runFor(Term::of(new DateTimeImmutable($from), new DateTimeImmutable($to), null, null));
        $tenancy->activate();

        if ($movedOut) {
            $tenancy->endOn(new DateTimeImmutable($to));
        }

        $tenancies->save($tenancy);
    }

    /**
     * Die Zahlungen, die aus den Mietverhaeltnissen folgen.
     *
     * Aus {@see DueAdvances} und nicht von Hand: so steht im Test dasselbe,
     * was die Anwendung beim Oeffnen des Jahres anlegt — samt der Frage, wie
     * sie die Faelligkeiten auf zwei Mietverhaeltnisse verteilt.
     */
    private static function theOperatingCostPayments(string $unitId): void
    {
        $due = self::getContainer()->get(DueAdvances::class);
        self::assertInstanceOf(DueAdvances::class, $due);

        $payments = [];

        foreach ($due->forUnit($unitId, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31')) as $owed) {
            if (AdvanceKind::OperatingCosts === $owed->kind) {
                $payments[] = new AdvancePayment($unitId, $owed->kind, 2026, $owed->dueOn, $owed->expected);
            }
        }

        self::paid()->saveAll($payments);
    }

    private static function aTenant(int $reference, string $given, string $family): Party
    {
        foreach (self::parties()->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if ($reference === $known->reference()) {
                return $known;
            }
        }

        $tenant = new Party(
            $reference,
            PartyKind::Person,
            $family,
            $given,
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Mietweg '.$reference, '40233', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('mieter'.$reference.'@example.org')]),
        );
        self::parties()->save($tenant);

        return $tenant;
    }

    private static function removeTheTenancies(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();
        // Die Mietverhaeltnisse halten die Einheit — sie gehen zuerst, sonst
        // kommt das Objekt nicht weg. Und nur die dieses Objekts: ein
        // `DELETE` ohne Bedingung raeumt in einer Testdatenbank auch das weg,
        // was ein anderer Test gerade braucht.
        $connection->executeStatement(
            'DELETE FROM tenancy WHERE unit_id IN (SELECT id FROM property_unit WHERE property_id = ?)',
            [self::propertyId()],
        );
        $connection->executeStatement('DELETE FROM party WHERE reference >= ? AND reference <= ?', [
            self::FIRST_TENANT,
            self::FIRST_TENANT + \count(self::NAMES),
        ]);
    }

    /**
     * Die Nebenkostenabrechnungen der ersten Einheit eines Laufs.
     *
     * @return list<StatementDocument>
     */
    private static function tenantDocumentsOf(Statement $statement): array
    {
        $found = array_values(array_filter(
            $statement->documents(),
            static fn (StatementDocument $d): bool => StatementKind::OperatingCosts === $d->kind() && 1 === $d->unitNumber(),
        ));
        usort(
            $found,
            static fn (StatementDocument $one, StatementDocument $other): int => $one->periodFrom() <=> $other->periodFrom(),
        );

        return $found;
    }

    /** Der Mietbeginn wird berichtigt — nachdem schon abgerechnet wurde. */
    private static function correctTheStartTo(string $day): void
    {
        $tenancies = self::tenancies();
        $unit = self::unitIds()[0] ?? '';

        foreach ($tenancies->forUnits([$unit])[$unit] ?? [] as $tenancy) {
            $tenancy->runFor(Term::of(
                new DateTimeImmutable($day),
                $tenancy->term()->endsOn(),
                null,
                null,
            ));
            $tenancies->save($tenancy);
        }
    }

    private static function release(): ReleaseStatement
    {
        $found = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $found);

        return $found;
    }

    private static function corrections(): CorrectStatement
    {
        $found = self::getContainer()->get(CorrectStatement::class);
        self::assertInstanceOf(CorrectStatement::class, $found);

        return $found;
    }

    private static function tenancies(): TenancyRepository
    {
        $found = self::getContainer()->get(TenancyRepository::class);
        self::assertInstanceOf(TenancyRepository::class, $found);

        return $found;
    }
}
