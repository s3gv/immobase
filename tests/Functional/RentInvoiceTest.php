<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ComposeRentInvoice;
use App\Module\Billing\Application\IssueRentInvoice;
use App\Module\Billing\Application\RentInvoiceGaps;
use App\Module\Billing\Application\ReviseRentInvoice;
use App\Module\Billing\Application\StartRentInvoice;
use App\Module\Billing\Application\StepsWithoutAnInvoice;
use App\Module\Billing\Domain\ProposedInvoice;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceCannotBeCorrected;
use App\Module\Billing\Domain\RentInvoiceIsIncomplete;
use App\Module\Billing\Domain\RentInvoiceIsOutOfOrder;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\TaxId;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Domain\EInvoiceTerms;
use App\Module\Tenancy\Domain\Payment;
use App\Module\Tenancy\Domain\PaymentDue;
use App\Module\Tenancy\Domain\PaymentMethod;
use App\Module\Tenancy\Domain\Taxation;
use App\Module\Tenancy\Domain\Tenancy;
use App\Shared\Ui\Page;
use App\Tests\Module\Billing\Fixture\FailsTheSecondWrite;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Throwable;

/**
 * Die Dauermietrechnung.
 *
 * Die Zusicherung, an der alles haengt: **eine ausgestellte Rechnung aendert
 * sich nicht mehr.** Sie liegt beim Mieter und traegt eine Nummer, mit der er
 * Vorsteuer zieht; wuerde sie beim naechsten Aufruf andere Zahlen zeigen,
 * haetten beide Seiten verschiedene Belege ueber denselben Vorgang.
 *
 * Die zweite: **die Kette der Fassungen ist lueckenlos.** Jeder Tag gehoert
 * genau einer — sonst gibt es Zeitraeume, ueber die zwei Rechnungen sprechen,
 * oder solche, ueber die keine spricht.
 */
final class RentInvoiceTest extends WebTestCase
{
    use BuildsALetProperty;

    protected function setUp(): void
    {
        self::bootKernel();
        self::buildTheLetProperty();
    }

    protected function tearDown(): void
    {
        self::removeTheLetProperty();

        parent::tearDown();
    }

    /** Die Betraege kommen aus der Mietstufe, die am Tag „gilt ab" gilt. */
    public function testTheAmountsComeFromTheStepThatApplies(): void
    {
        self::alsoRaisesTheRent('2027-07-01');
        $body = self::composed(self::anInvoice('2027-07-01'));

        self::assertSame(195000, $body->base->cents(), 'Die zweite Stufe, nicht die erste');
        self::assertSame(248000, $body->net()->cents());
        self::assertSame(47120, $body->tax()->cents(), '19 % auf 2.480 €');
        self::assertSame(295120, $body->gross()->cents());
    }

    /** Ein Stellplatz von null steht nicht auf dem Blatt — er behauptete einen, den es nicht gibt. */
    public function testAnEmptyPositionIsNotListed(): void
    {
        $keys = array_column(self::composed(self::anInvoice('2026-01-01'))->lines(), 'key');

        self::assertSame(['base', 'operating', 'heating'], $keys);
    }

    /**
     * Eine Mietaenderung nach der Ausstellung aendert das Schreiben nicht.
     *
     * Der Kernsatz. Ohne ihn waere jede zugestellte Rechnung ein Dokument,
     * das sich hinter dem Ruecken des Mieters aendert — und der Mieter
     * haette einen Ausdruck, den die Anwendung nicht mehr kennt.
     *
     * Geaendert wird hier die Stufe, aus der die Rechnung gerechnet wurde.
     * Eine **neue** Stufe weiter hinten waere kein Nachweis: die Rechnung
     * liest die Stufe ihres eigenen Tages und bliebe auch ohne Einfrieren
     * stehen.
     */
    public function testARentChangeAfterIssuingDoesNotChangeTheInvoice(): void
    {
        $invoice = self::issued(self::anInvoice('2026-01-01'));
        $before = self::composed($invoice)->gross()->cents();

        self::alsoCorrectsTheRent();

        self::assertSame($before, self::composed(self::reloaded($invoice))->gross()->cents());
    }

    /** Ein Entwurf dagegen rechnet mit — bis zur Ausstellung ist nichts fest. */
    public function testADraftFollowsTheRent(): void
    {
        $invoice = self::anInvoice('2026-01-01');
        self::assertSame(273700, self::composed($invoice)->gross()->cents());

        self::alsoCorrectsTheRent();

        self::assertSame(285600, self::composed(self::reloaded($invoice))->gross()->cents());
    }

    /** Die Folgefassung setzt das Ende der vorigen auf den Vortag. */
    public function testASuccessorClosesThePreviousOne(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        self::alsoRaisesTheRent('2027-07-01');
        self::issued(self::revise()->succeed($first, new DateTimeImmutable('2027-07-01')));

        $closed = self::reloaded($first)->validity();

        self::assertFalse($closed->isOpenEnded(), 'Die vorige ist nicht mehr offen');
        self::assertSame('2027-06-30', $closed->until()?->format('Y-m-d'));
    }

    /** Eine Berichtigung aendert den Zeitraum nicht — sie wirkt zurueck. */
    public function testACorrectionKeepsThePeriod(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        $correction = self::revise()->correct($first);

        self::assertSame('2026-01-01', $correction->validity()->from()->format('Y-m-d'));
        self::assertSame(1, $correction->edition()->number(), 'Dieselbe Fassung');
        self::assertSame(2, $correction->edition()->iteration(), 'Eine Iteration weiter');
        self::assertSame('DM-'.self::tenancyNumber().'-1-2', $correction->reference());
    }

    /**
     * Und sie laesst sich auch nicht nachtraeglich verschieben.
     *
     * Der erste Schritt zeigt den Tag, aber er nimmt keinen neuen an. Wer
     * ihn per POST setzte, bekaeme eine Berichtigung ueber einen anderen
     * Zeitraum als das Schreiben, das sie berichtigt — Tage mit zwei
     * Rechnungen und Tage mit keiner.
     */
    public function testACorrectionKeepsThePeriodEvenWhenAskedOtherwise(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        $correction = self::revise()->correct($first);

        self::start()->describe($correction, new DateTimeImmutable('2027-01-01'), 'Verschoben');

        self::assertSame('2026-01-01', self::reloaded($correction)->validity()->from()->format('Y-m-d'));
        self::assertSame('Verschoben', self::reloaded($correction)->label(), 'Die Bezeichnung schon');
    }

    /** Eine Folgefassung dagegen darf umziehen — sie gilt ab ihrem eigenen Tag. */
    public function testASuccessorCanBeMoved(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        self::alsoRaisesTheRent('2027-07-01');
        $next = self::revise()->succeed($first, new DateTimeImmutable('2027-07-01'));

        self::start()->describe($next, new DateTimeImmutable('2027-08-01'), 'Ab August');

        self::assertSame('2027-08-01', self::reloaded($next)->validity()->from()->format('Y-m-d'));
    }

    /** Ein Entwurf wird geaendert, nicht berichtigt — er hat nie gegolten. */
    public function testADraftCannotBeCorrected(): void
    {
        $this->expectException(RentInvoiceCannotBeCorrected::class);
        self::revise()->correct(self::anInvoice('2026-01-01'));
    }

    /** Und eine ueberholte Fassung auch nicht. */
    public function testAnOutdatedVersionCannotBeCorrected(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        self::alsoRaisesTheRent('2027-07-01');
        self::issued(self::revise()->succeed($first, new DateTimeImmutable('2027-07-01')));

        $this->expectException(RentInvoiceCannotBeCorrected::class);
        self::revise()->correct(self::reloaded($first));
    }

    /**
     * Eine Fassung kann nur auf die zuletzt ausgestellte folgen.
     *
     * Sonst laesst sich die dritte vor der zweiten ausstellen — und die
     * zweite schliesst danach nichts mehr, weil die dritte schon die
     * juengste ist. Zwei offene Fassungen, beide gueltig, beide beim
     * Mieter.
     */
    public function testAVersionCannotSkipTheOneBefore(): void
    {
        self::issued(self::anInvoice('2026-01-01'));
        $third = self::aDraftNumbered(3, '2027-07-01');

        $this->expectException(RentInvoiceIsOutOfOrder::class);
        self::issue()->issue($third, new DateTimeImmutable('today'));
    }

    /**
     * Und sie muss spaeter beginnen als die, auf die sie folgt.
     *
     * Eine rueckdatierte Fassung schloesse die vorige auf einen Tag, an dem
     * sie selbst laengst gilt — und weil ein Ende vor dem Beginn kein
     * Zeitraum ist, bliebe die vorige stattdessen bis zu ihrem eigenen
     * ersten Tag stehen. Zwei Rechnungen ueber dieselben Monate.
     */
    public function testAVersionMustBeginAfterTheOneBefore(): void
    {
        $first = self::issued(self::anInvoice('2026-06-01'));
        $next = self::revise()->succeed($first, new DateTimeImmutable('2026-03-01'));

        $this->expectException(RentInvoiceIsOutOfOrder::class);
        self::issue()->issue($next, new DateTimeImmutable('today'));
    }

    /** Am selben Tag zu beginnen reicht auch nicht — die vorige haette nie gegolten. */
    public function testAVersionCannotBeginOnTheSameDay(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        $next = self::revise()->succeed($first, new DateTimeImmutable('2026-01-01'));

        $this->expectException(RentInvoiceIsOutOfOrder::class);
        self::issue()->issue($next, new DateTimeImmutable('today'));
    }

    /**
     * Eine Berichtigung der ueberholten Fassung bleibt liegen.
     *
     * Der Entwurf entsteht, solange die Fassung noch die juengste ist —
     * und traegt deren damals offene Geltung. Geht in der Zwischenzeit eine
     * Folgefassung hinaus, waere die Berichtigung beim Ausstellen wieder ab
     * dem alten Tag offen und laege ueber der neuen. Also wird sie am Ende
     * noch einmal geprueft und nicht nur am Anfang.
     */
    public function testACorrectionCannotBeIssuedAfterASuccessor(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        $correction = self::revise()->correct($first);

        self::alsoRaisesTheRent('2027-07-01');
        self::issued(self::revise()->succeed($first, new DateTimeImmutable('2027-07-01')));

        $this->expectException(RentInvoiceIsOutOfOrder::class);
        self::issue()->issue($correction, new DateTimeImmutable('today'));
    }

    /** Ohne Folgefassung dazwischen geht sie hinaus — mit dem Zeitraum der Fassung. */
    public function testACorrectionOfTheLatestVersionCanBeIssued(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        $correction = self::issued(self::revise()->correct($first));

        self::assertFalse(self::reloaded($correction)->release()->isDraft());
        self::assertSame('2026-01-01', $correction->validity()->from()->format('Y-m-d'));
        self::assertTrue(
            self::reloaded($first)->validity()->isOpenEnded(),
            'Die berichtigte Fassung wird nicht geschlossen — sie wird ersetzt',
        );
    }

    /**
     * Zweimal nach einer Berichtigung gefragt gibt denselben Entwurf.
     *
     * Zwei Entwuerfe traegen dieselbe Fassungs- und Iterationsnummer; der
     * zweite lief in den eindeutigen Index der Datenbank.
     */
    public function testAskingTwiceForACorrectionReusesTheDraft(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));

        $once = self::revise()->correct($first);
        $again = self::revise()->correct($first);

        self::assertSame($once->id(), $again->id());
    }

    /**
     * Zweimal nach einer Folgefassung gefragt gibt denselben Entwurf.
     *
     * Zwei offene Entwuerfe fuer dieselbe Fortsetzung waeren zwei
     * Rechnungen ueber denselben Zeitraum, sobald beide hinausgehen.
     */
    public function testAskingTwiceForASuccessorReusesTheDraft(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        self::alsoRaisesTheRent('2027-07-01');

        $once = self::revise()->succeed($first, new DateTimeImmutable('2027-07-01'));
        $again = self::revise()->succeed($first, new DateTimeImmutable('2028-01-01'));

        self::assertSame($once->id(), $again->id());
        self::assertSame('2027-07-01', $again->validity()->from()->format('Y-m-d'), 'Der Tag bleibt, wie er war');
    }

    /** Eine Berichtigung ist keine Folgefassung — sie wird nicht wiederverwendet. */
    public function testACorrectionDraftIsNotReusedAsASuccessor(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        $correction = self::revise()->correct($first);
        $next = self::revise()->succeed($first, new DateTimeImmutable('2027-07-01'));

        self::assertNotSame($correction->id(), $next->id());
    }

    /**
     * Ueber einen Tag ausserhalb der Laufzeit gibt es keine Rechnung.
     *
     * Der beendete Vertrag steht bewusst zur Wahl — wer eine Rechnung von
     * damals nachtraegt, findet ihn sonst nicht mehr. „Damals" heisst aber
     * waehrend der Laufzeit; ab dem Tag danach hat der Vermieter nichts
     * mehr zu leisten, und eine Rechnung darueber waere ein Steuerausweis
     * ohne Umsatz.
     */
    public function testAnInvoiceOutsideTheTermIsIncomplete(): void
    {
        self::theLettingEndsOn('2026-12-31');
        $invoice = self::anInvoice('2027-01-01');

        self::assertContains(
            'billing.invoice.missing.not_running',
            RentInvoiceGaps::of($invoice, self::composed($invoice), self::compose()->tenancyOf($invoice)),
        );

        $this->expectException(RentInvoiceIsIncomplete::class);
        self::issue()->issue($invoice, new DateTimeImmutable('today'));
    }

    /** Innerhalb der Laufzeit dagegen schon — auch wenn der Vertrag laengst beendet ist. */
    public function testAnInvoiceWithinTheTermOfAnEndedTenancyIsFine(): void
    {
        self::theLettingEndsOn('2026-12-31');
        $invoice = self::anInvoice('2026-01-01');

        self::assertSame([], RentInvoiceGaps::of($invoice, self::composed($invoice), self::compose()->tenancyOf($invoice)));
    }

    /**
     * Scheitert das Ausstellen, bleibt die vorige Fassung offen.
     *
     * Zuerst wird die Vorgaengerin geschlossen, dann die neue eingefroren.
     * Bliebe die erste Haelfte stehen, waere die Vorgaengerin beendet, ohne
     * dass eine Nachfolgerin gilt — genau die Luecke, die das Schliessen
     * verhindern soll.
     *
     * Gearbeitet wird mit der echten Datenbank; nur der zweite
     * Schreibvorgang wirft ({@see FailsTheSecondWrite}). Geprueft wird also
     * nicht, dass wir eine Transaktion aufrufen, sondern dass hinterher
     * nichts davon stehen geblieben ist.
     */
    public function testAFailedIssueLeavesThePreviousOneOpen(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        self::alsoRaisesTheRent('2027-07-01');
        $next = self::revise()->succeed($first, new DateTimeImmutable('2027-07-01'));
        $breaking = new IssueRentInvoice(new FailsTheSecondWrite(self::invoices()), self::compose());

        try {
            $breaking->issue($next, new DateTimeImmutable('today'));
            self::fail('Der zweite Schreibvorgang haette scheitern muessen.');
        } catch (Throwable) {
            self::resetTheEntityManager();
        }

        self::assertTrue(self::reloaded($first)->validity()->isOpenEnded(), 'Die vorige gilt weiter');
        self::assertTrue(self::reloaded($next)->release()->isDraft(), 'Und die neue ist nicht ausgestellt');
    }

    /**
     * Umsatzsteuer ohne Steuernummer haelt die Ausstellung auf.
     *
     * § 14 Abs. 4 Nr. 2 UStG verlangt die Nummer des leistenden
     * Unternehmers. Ohne sie zieht der Mieter keine Vorsteuer — und er merkt
     * es erst, wenn sein Finanzamt es ihm sagt.
     */
    public function testVatWithoutATaxNumberStopsTheIssue(): void
    {
        self::theLandlordForgetsTheirTaxNumber();
        $invoice = self::anInvoice('2026-01-01');

        self::assertContains(
            'billing.invoice.missing.tax_number',
            RentInvoiceGaps::of($invoice, self::composed($invoice), self::compose()->tenancyOf($invoice)),
        );

        $this->expectException(RentInvoiceIsIncomplete::class);
        self::issue()->issue($invoice, new DateTimeImmutable('today'));
    }

    /**
     * Mit Umsatzsteuer haelt fehlendes E-Rechnungs-Zeug das Ausstellen auf — benannt.
     *
     * Ab 2028 ist eine Rechnung zwischen Unternehmen ohne E-Rechnung keine.
     * Was fehlt, steht als Name da: die Referenz des Mieters, die Adresse,
     * der Ansprechpartner — und bei Lastschrift das Mandat.
     */
    public function testMissingEInvoiceDataStopsTheIssue(): void
    {
        self::theTenantGaveNothing(PaymentMethod::DirectDebit);
        self::forgetTheManagement();
        $invoice = self::anInvoice('2026-01-01');

        $gaps = RentInvoiceGaps::of($invoice, self::composed($invoice), self::compose()->tenancyOf($invoice));

        foreach (['buyer_reference', 'buyer_e_address', 'contact_name', 'contact_phone', 'contact_email', 'sepa_mandate', 'debtor_iban', 'creditor_id'] as $key) {
            self::assertContains('billing.einvoice.missing.'.$key, $gaps);
        }

        $this->expectException(RentInvoiceIsIncomplete::class);
        self::issue()->issue($invoice, new DateTimeImmutable('today'));
    }

    /** Steuerfrei fragt niemand nach einer E-Rechnung — Wohnraum ist dauerhaft ausgenommen. */
    public function testAnExemptLettingNeedsNoEInvoiceData(): void
    {
        self::theTenantGaveNothing(PaymentMethod::Transfer);
        self::theLettingIsExempt();
        $invoice = self::anInvoice('2026-01-01');

        $gaps = RentInvoiceGaps::of($invoice, self::composed($invoice), self::compose()->tenancyOf($invoice));

        self::assertSame([], array_values(array_filter($gaps, static fn (string $key): bool => str_starts_with($key, 'billing.einvoice.'))));
    }

    /** Beim Ausstellen wird eingefroren, was die E-Rechnung braucht. */
    public function testTheIssueFreezesTheEInvoiceData(): void
    {
        $invoice = self::anInvoice('2026-01-01');
        self::issue()->issue($invoice, new DateTimeImmutable('2026-01-01'));

        $data = self::composed($invoice)->eInvoice;

        self::assertTrue($data->isCaptured());
        self::assertSame('LADEN-4711', $data->buyerReference());
        self::assertSame(['street' => 'Eigentümerallee 1', 'postalCode' => '40233', 'city' => 'Düsseldorf'], $data->seller());
        self::assertSame(['street' => 'Geschäftsweg 7', 'postalCode' => '40213', 'city' => 'Düsseldorf'], $data->buyer());
        self::assertSame('verwaltung@example.org', $data->contact()['email']);
        self::assertSame('third_working_day', $data->paymentDue());

        // Und danach aendert der Mieter sein Postfach — die Rechnung nicht.
        self::theTenantGaveNothing(PaymentMethod::Transfer);
        self::assertSame('LADEN-4711', self::composed($invoice)->eInvoice->buyerReference());
    }

    /** Ohne Steuerausweis haelt sie nichts auf — dort besteht gar keine Rechnungspflicht. */
    public function testWithoutVatTheTaxNumberIsNotRequired(): void
    {
        self::theLandlordForgetsTheirTaxNumber();
        self::theLettingIsExempt();
        $invoice = self::anInvoice('2026-01-01');

        self::assertNotContains(
            'billing.invoice.missing.tax_number',
            RentInvoiceGaps::of($invoice, self::composed($invoice), self::compose()->tenancyOf($invoice)),
        );
    }

    /** Bei steuerfreier Vermietung steht keine Umsatzsteuer auf dem Blatt. */
    public function testAnExemptLettingShowsNoVat(): void
    {
        self::theLettingIsExempt();
        $body = self::composed(self::anInvoice('2026-01-01'));

        self::assertFalse($body->taxation->isCharged());
        self::assertSame(0, $body->tax()->cents());
        self::assertSame($body->net()->cents(), $body->gross()->cents());
    }

    /**
     * Vorgeschlagen wird nur, wo schon einmal ausgestellt wurde.
     *
     * Sonst bekaeme eine Verwaltung mit zweihundert Wohnungen zweihundert
     * Hinweise und gewoehnte sich alle ab — auch die zwei echten.
     */
    public function testNothingIsProposedBeforeTheFirstInvoice(): void
    {
        self::alsoRaisesTheRent('2027-07-01');

        self::assertSame([], self::pending()->all());
    }

    /**
     * Nach der ersten meldet die Uebersicht jede Stufe ohne Rechnung.
     *
     * Auch wenn die letzte Fassung offen ist: sie deckt den Tag ab, traegt
     * aber die Betraege der alten Stufe. Genau das ist die gefaehrliche Lage
     * — eine Rechnung im Umlauf, die zu wenig fordert.
     */
    public function testAStepAfterAnOpenInvoiceIsStillReported(): void
    {
        self::issued(self::anInvoice('2026-01-01'));
        self::alsoRaisesTheRent('2027-07-01');

        $pending = self::pending()->all();

        self::assertCount(1, $pending);
        self::assertSame('2027-07-01', $pending[0]['from']->format('Y-m-d'));
    }

    /** Ist die Stufe abgedeckt, meldet sie niemand mehr. */
    public function testACoveredStepIsNotReported(): void
    {
        $first = self::issued(self::anInvoice('2026-01-01'));
        self::alsoRaisesTheRent('2027-07-01');
        self::issued(self::revise()->succeed($first, new DateTimeImmutable('2027-07-01')));

        self::assertSame([], self::pending()->all());
    }

    private static function anInvoice(string $from): RentInvoice
    {
        $invoice = self::start()->forTenancy(
            self::letTenancyId(),
            new DateTimeImmutable($from),
            'Dauermietrechnung',
        );
        self::assertInstanceOf(RentInvoice::class, $invoice);

        return $invoice;
    }

    private static function issued(RentInvoice $invoice): RentInvoice
    {
        self::issue()->issue($invoice, new DateTimeImmutable('today'));

        return $invoice;
    }

    private static function reloaded(RentInvoice $invoice): RentInvoice
    {
        $invoices = self::getContainer()->get(RentInvoiceRepository::class);
        self::assertInstanceOf(RentInvoiceRepository::class, $invoices);
        $found = $invoices->byId($invoice->id());
        self::assertInstanceOf(RentInvoice::class, $found);

        return $found;
    }

    private static function composed(RentInvoice $invoice): ProposedInvoice
    {
        return self::compose()->of($invoice);
    }

    private static function tenancyNumber(): int
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);

        return $tenancy->number();
    }

    /** Ein Entwurf mit einer Fassungsnummer, die die Anwendung so nie vergaebe. */
    private static function aDraftNumbered(int $number, string $from): RentInvoice
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);

        $units = self::getContainer()->get(UnitDirectory::class);
        self::assertInstanceOf(UnitDirectory::class, $units);
        $unit = $units->byIds([$tenancy->unitId()])[$tenancy->unitId()] ?? null;
        self::assertInstanceOf(UnitBrief::class, $unit);

        $invoice = new RentInvoice(
            $number,
            $tenancy->id(),
            $tenancy->number(),
            $unit->propertyId,
            new DateTimeImmutable($from),
        );
        self::invoices()->save($invoice);

        return $invoice;
    }

    private static function theLettingEndsOn(string $day): void
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);
        $tenancy->runFor($tenancy->term()->endingOn(new DateTimeImmutable($day)));
        self::tenancies()->save($tenancy);
    }

    /**
     * Nach einem gescheiterten Schreibvorgang ist der Manager geschlossen.
     *
     * Doctrine laesst danach keine weitere Abfrage zu — der Test will aber
     * nachsehen, was in der Datenbank steht.
     */
    private static function resetTheEntityManager(): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $registry->resetManager();
    }

    private static function invoices(): RentInvoiceRepository
    {
        $invoices = self::getContainer()->get(RentInvoiceRepository::class);
        self::assertInstanceOf(RentInvoiceRepository::class, $invoices);

        return $invoices;
    }

    private static function theLandlordForgetsTheirTaxNumber(): void
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        foreach ($parties->matching(PartyFilter::none(), Page::of(1, 500)) as $party) {
            if (98001 === $party->reference()) {
                $party->taxedAs(TaxId::of(''));
                $parties->save($party);
            }
        }
    }

    private static function theTenantGaveNothing(PaymentMethod $method): void
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);
        $tenancy->paidBy(Payment::of($method, PaymentDue::ThirdWorkingDay, EInvoiceTerms::none()));
        self::tenancies()->save($tenancy);
    }

    private static function theLettingIsExempt(): void
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);
        $tenancy->taxAs(Taxation::exempt());
        self::tenancies()->save($tenancy);
    }

    private static function start(): StartRentInvoice
    {
        $start = self::getContainer()->get(StartRentInvoice::class);
        self::assertInstanceOf(StartRentInvoice::class, $start);

        return $start;
    }

    private static function issue(): IssueRentInvoice
    {
        $issue = self::getContainer()->get(IssueRentInvoice::class);
        self::assertInstanceOf(IssueRentInvoice::class, $issue);

        return $issue;
    }

    private static function revise(): ReviseRentInvoice
    {
        $revise = self::getContainer()->get(ReviseRentInvoice::class);
        self::assertInstanceOf(ReviseRentInvoice::class, $revise);

        return $revise;
    }

    private static function compose(): ComposeRentInvoice
    {
        $compose = self::getContainer()->get(ComposeRentInvoice::class);
        self::assertInstanceOf(ComposeRentInvoice::class, $compose);

        return $compose;
    }

    private static function pending(): StepsWithoutAnInvoice
    {
        $pending = self::getContainer()->get(StepsWithoutAnInvoice::class);
        self::assertInstanceOf(StepsWithoutAnInvoice::class, $pending);

        return $pending;
    }
}
