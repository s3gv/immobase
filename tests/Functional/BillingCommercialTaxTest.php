<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Application\CorrectStatement;
use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Application\ReleaseStatement;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Proposal;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementIsIncomplete;
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
use App\Module\Party\Domain\TaxId;
use App\Module\Tenancy\Domain\EInvoiceTerms;
use App\Module\Tenancy\Domain\Payment;
use App\Module\Tenancy\Domain\PaymentDue;
use App\Module\Tenancy\Domain\PaymentMethod;
use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Taxation;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\Term;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use App\Tests\Shared\EInvoice\CiiSchema;
use App\Tests\Shared\EInvoice\XRechnungRules;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Betriebskostenabrechnung eines Gewerbemieters mit Umsatzsteuer.
 *
 * Einheit 1 ist an ein Unternehmen vermietet, das nach § 9 UStG optiert hat;
 * Einheit 2 an eine Privatperson, steuerfrei. Beide stehen in derselben
 * Abrechnung — und genau das ist der Fall, in dem es still falsch wird: eine
 * Regel fuer die eine Einheit, die die andere mitnimmt.
 *
 * Die Zahlen sind von Hand gerechnet und stehen hier als Zahlen, nicht als
 * Formel. Eine Formel im Test waere dieselbe wie im Code, und beide waeren
 * gleich falsch.
 *
 * * Prüfsteuer 1.240,50 € nach Wohnfläche, keine Umsatzsteuer darin:
 *   Einheit 1 traegt 682,02 €.
 * * Prüfdienst 480,00 € nach Einheiten, darin 76,64 € Umsatzsteuer:
 *   Einheit 1 traegt 240,00 €, darin 38,32 € — netto 201,68 €.
 * * Kosten netto 883,70 €, 19 % sind 167,90 €, brutto 1.051,60 €.
 * * Vorauszahlung 150,00 € netto, also 178,50 € brutto im Monat,
 *   zwoelfmal: 2.142,00 €, darin 342,00 € Umsatzsteuer.
 * * Guthaben: 1.051,60 − 2.142,00 = −1.090,40 €.
 */
final class BillingCommercialTaxTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ConfiguresTheManagement;
    use ForgetsRateLimits;
    use ReadsPdfArchives;
    use SignsIn;

    private const int COMPANY = 99101;
    private const int PERSON = 99102;

    protected function tearDown(): void
    {
        self::removeTheTenancies();
        self::removeTheProperty();
        self::forgetTheManagement();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Mit Umsatzsteuer vermietet, wird die Vorauszahlung brutto erwartet. */
    public function testTheAdvanceOfATaxedTenancyIsExpectedGross(): void
    {
        self::letBothUnits();

        $due = self::dueFor(self::unitIds()[0] ?? '');
        self::assertCount(12, $due);

        foreach ($due as $owed) {
            self::assertSame(17850, $owed->expected->cents(), 'Netto 150,00 € und 19 %');
        }

        foreach (self::dueFor(self::unitIds()[1] ?? '') as $owed) {
            self::assertSame(15000, $owed->expected->cents(), 'Steuerfrei bleibt es der Betrag aus dem Vertrag');
        }
    }

    /** Die Abrechnung rechnet netto, weist die Steuer aus und setzt die Vorauszahlungen samt Steuer ab. */
    public function testTheTaxedTenantIsBilledNet(): void
    {
        $tenant = self::tenantLetterFor(1, self::billedProposal());

        self::assertTrue($tenant->letting->isTaxed());
        self::assertSame(88370, $tenant->costs()->cents(), 'Kosten netto');
        self::assertSame(16790, $tenant->tax()->cents(), '19 % auf die Nettosumme');
        self::assertSame(214200, $tenant->paid()->cents(), 'Zwölf Vorauszahlungen brutto');
        self::assertSame(34200, $tenant->advancesTax()->cents(), 'Die Steuer darin');
        self::assertSame(-109040, $tenant->balance()->cents(), 'Guthaben');
    }

    /** Wer nicht optiert hat, bekommt dieselbe Abrechnung wie immer. */
    public function testTheExemptTenantAndTheOwnerStayAsTheyWere(): void
    {
        $proposal = self::billedProposal();
        $person = self::tenantLetterFor(2, $proposal);

        self::assertFalse($person->letting->isTaxed());
        self::assertSame(0, $person->tax()->cents());
        self::assertSame(0, $person->advancesTax()->cents());
        self::assertSame(
            $person->costs()->minus($person->paid())->cents(),
            $person->balance()->cents(),
            'Kosten minus Vorauszahlungen, sonst nichts',
        );
        self::assertSame(
            55848 + 24000,
            $person->costs()->cents(),
            'Brutto: die Steuer im Prüfdienst bleibt drin',
        );

        foreach ($proposal->documents as $document) {
            if (StatementKind::HouseMoney === $document->kind) {
                self::assertFalse($document->letting->isTaxed(), 'Hausgeld ist steuerfrei');
            }
        }
    }

    /** Die Freigabe friert Satz, Steuer und die Steuer in jeder Zeile ein. */
    public function testTheReleaseFreezesTheTax(): void
    {
        $frozen = self::releasedTenantLetterFor(1, self::aReleasedStatement());

        self::assertTrue($frozen->letting()->isTaxed());
        self::assertSame(1900, $frozen->letting()->taxation()->rateBps());
        self::assertSame(88370, $frozen->outcome()->costs()->cents());
        self::assertSame(16790, $frozen->outcome()->tax()->cents());
        self::assertSame(34200, $frozen->outcome()->advancesTax()->cents());
        self::assertSame(-109040, $frozen->balance()->cents());
        self::assertNotNull($frozen->letting()->tenancyNumber());

        $service = null;

        foreach ($frozen->lines() as $line) {
            if ('Prüfdienst' === $line->costKind()) {
                $service = $line;
            }
        }

        self::assertNotNull($service);
        self::assertSame(3832, $service->inputTax()->cents());
        self::assertSame(20168, $service->net()->cents());
        self::assertSame(40336, $service->totalNet()->cents());
    }

    /**
     * Auf dem Blatt stehen Nettosumme, Steuer, Bruttosumme und die Steuer in den Vorauszahlungen.
     *
     * Eine Endrechnung, die die verrechneten Anzahlungen ohne ihre Steuer
     * absetzt, ist keine (§ 14 Abs. 5 UStG) — der Mieter zoege die Vorsteuer
     * doppelt.
     */
    public function testTheLetterShowsTheTax(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $statement = self::aReleasedStatement();

        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'/pdf');
        self::assertResponseIsSuccessful();

        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('Summe der Kosten netto', $text);
        self::assertStringContainsString('883,70', $text);
        self::assertStringContainsString('Umsatzsteuer 19,00 %', $text);
        self::assertStringContainsString('167,90', $text);
        self::assertStringContainsString('Summe der Kosten brutto', $text);
        self::assertStringContainsString('1.051,60', $text);
        self::assertStringContainsString('darin Umsatzsteuer 19,00 %', $text);
        self::assertStringContainsString('342,00', $text);
        self::assertStringContainsString('201,68', $text, 'Der Prüfdienst steht netto da');
        self::assertStringContainsString('Leistender Unternehmer: Paula Prüfer', $text, 'Wer die Rechnung stellt');
        self::assertStringContainsString('21/815/08150', $text, 'Mit Steuernummer');
    }

    /**
     * Fehlt, was die Rechnung braucht, geht der ganze Lauf nicht hinaus — benannt.
     *
     * Die Privatmieterin bekaeme ihre Abrechnung sonst, der Gewerbemieter
     * eine Rechnung ohne Referenz. Ein Lauf ist ein Stand; halb freigeben
     * gibt es nicht.
     */
    public function testMissingInvoiceDataStopsTheRelease(): void
    {
        self::letBothUnits(withTerms: false);
        $statement = self::aDraft();
        self::keepEverything($statement);

        $release = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $release);

        try {
            $release->release($statement, new DateTimeImmutable('2027-03-01'));
            self::fail('Die Freigabe hätte aufhalten müssen');
        } catch (StatementIsIncomplete $stopped) {
            self::assertStringContainsString('Prüfhandel GmbH', $stopped->getMessage(), 'Welches Schreiben');
            self::assertStringContainsString('Käuferreferenz', $stopped->getMessage(), 'Und was ihm fehlt');
        }

        self::assertTrue($statement->isDraft());
    }

    /** Die Freigabe friert ein, wer die Rechnung stellt und was die E-Rechnung braucht. */
    public function testTheReleaseFreezesTheSellerAndTheEInvoiceData(): void
    {
        $statement = self::aReleasedStatement();
        $frozen = self::releasedTenantLetterFor(1, $statement)->letting();

        self::assertSame('Paula Prüfer', $frozen->seller()->name());
        self::assertSame('21/815/08150', $frozen->seller()->taxNumber());
        self::assertSame('DE02120300000000202051', str_replace(' ', '', $frozen->seller()->payeeIban()));
        self::assertTrue($frozen->eInvoice()->isCaptured());
        self::assertSame('HANDEL-0815', $frozen->eInvoice()->buyerReference());
        self::assertSame('', $frozen->eInvoice()->paymentDue(), 'Eine Abrechnung hat keinen Fälligkeitstag aus dem Vertrag');

        foreach ($statement->documents() as $other) {
            if (!$other->letting()->isTaxed()) {
                self::assertFalse($other->letting()->eInvoice()->isCaptured(), 'Steuerfreie Schreiben tragen nichts davon');
            }
        }
    }

    /** Das Guthaben des Gewerbemieters als E-Rechnung — mit den Vorauszahlungen als bereits gezahlt. */
    public function testTheCreditLetterAsEInvoiceIsTheReferenceFile(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $statement = self::aReleasedStatement();

        $xml = self::eInvoiceOf($client, $statement, 1);

        self::assertStringEqualsFile(self::fixture('abrechnung-guthaben.xml'), self::normalised($xml, $statement));
        self::assertSame([], (new XRechnungRules())->violations($xml));
        self::assertSame([], CiiSchema::violations($xml));
    }

    /** Eine Nachzahlung nennt ihre Frist. */
    public function testTheDueLetterAsEInvoiceIsTheReferenceFile(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $statement = self::aReleasedStatement(monthlyAdvance: 5000);

        $xml = self::eInvoiceOf($client, $statement, 1);

        self::assertStringEqualsFile(self::fixture('abrechnung-nachzahlung.xml'), self::normalised($xml, $statement));
        self::assertSame([], (new XRechnungRules())->violations($xml));
        self::assertSame([], CiiSchema::violations($xml));
    }

    /**
     * Eine Korrektur fordert die Differenz — dieselbe, die auf dem Blatt steht.
     *
     * Zuerst ein Guthaben von 1.090,40 €. Danach steigen die Kosten, und die
     * Korrektur ergibt immer noch ein Guthaben, aber ein kleineres: unterm
     * Strich bleibt eine Nachzahlung. Die XML darf weder das volle neue
     * Guthaben erstatten noch es als Guthaben auszeichnen — sie fordert, was
     * das Blatt fordert.
     */
    public function testACorrectionAsEInvoiceAsksForTheDifference(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $original = self::aReleasedStatement();
        self::raiseTheCosts();

        $corrections = self::getContainer()->get(CorrectStatement::class);
        self::assertInstanceOf(CorrectStatement::class, $corrections);
        $correction = $corrections->of($original);
        $release = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $release);
        $release->release($correction, new DateTimeImmutable('2027-04-01'));

        $letter = self::releasedTenantLetterFor(1, $correction);
        self::assertTrue($letter->outcome()->result()->isNegative(), 'Das neue Ergebnis ist noch ein Guthaben');
        self::assertFalse($letter->balance()->isNegative(), 'Unterm Strich bleibt eine Nachzahlung');

        $xml = self::eInvoiceOf($client, $correction, 1);
        $read = static fn (string $element): string => 1 === preg_match('#<ram:'.$element.'[^>]*>([^<]*)</ram:'.$element.'>#', $xml, $m) && isset($m[1]) ? $m[1] : '';

        self::assertSame('384', $read('TypeCode'));
        self::assertSame($letter->balance()->toDecimal(), $read('DuePayableAmount'), 'Der Zahlbetrag ist die Differenz vom Blatt');
        self::assertSame($letter->outcome()->advances()->plus($letter->outcome()->settled() ?? Money::zero())->toDecimal(), $read('TotalPrepaidAmount'));
        self::assertSame('Zahlbar innerhalb von 30 Tagen nach Zugang.', $read('Description'), 'Eine Nachzahlung, kein Guthaben');
        self::assertStringContainsString('<ram:IssuerAssignedID>'.self::releasedTenantLetterFor(1, $original)->reference()->toString().'</ram:IssuerAssignedID>', $xml);
        self::assertSame([], (new XRechnungRules())->violations($xml));
        self::assertSame([], CiiSchema::violations($xml));
    }

    /** Die Privatmieterin bekommt keine — und ihre Seite zeigt keinen Knopf. */
    public function testTheExemptLetterHasNoEInvoice(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $statement = self::aReleasedStatement();
        $exempt = self::releasedTenantLetterFor(2, $statement);

        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'/dokumente/'.$exempt->id().'/xrechnung');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'?empfaenger='.$exempt->id());
        self::assertSelectorNotExists('a[href$="/xrechnung"]');

        $taxed = self::releasedTenantLetterFor(1, $statement);
        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'?empfaenger='.$taxed->id());
        self::assertSelectorTextContains('a[href$="/xrechnung"]', 'E-Rechnung (XML)');
    }

    protected static function testEmail(): string
    {
        return 'gewerbesteuer@example.org';
    }

    private static function aReleasedStatement(int $monthlyAdvance = 15000): Statement
    {
        self::letBothUnits(monthlyAdvance: $monthlyAdvance);
        $statement = self::aDraft();
        self::keepEverything($statement);

        $release = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $release);
        $release->release($statement, new DateTimeImmutable('2027-03-01'));

        return $statement;
    }

    private static function billedProposal(): Proposal
    {
        self::letBothUnits();
        $statement = self::aDraft();

        $compose = self::getContainer()->get(ComposeStatement::class);
        self::assertInstanceOf(ComposeStatement::class, $compose);
        $offered = $compose->offered($statement);

        return $compose->of(
            $statement,
            array_map(static fn (object $cost): string => $cost->costYearId, $offered['costs']),
            array_map(static fn (object $payment): string => $payment->paymentId, $offered['payments']),
        );
    }

    /** Das Objekt, die enthaltene Steuer im Prüfdienst, zwei Mietverhältnisse und ihre Zahlungen. */
    private static function letBothUnits(bool $withTerms = true, int $monthlyAdvance = 15000): void
    {
        self::buildTheProperty();
        self::theManagementIsReachable();
        self::theOwnerHasATaxNumber();

        foreach (self::items()->forProperty(self::propertyId()) as $item) {
            if (90002 === $item->number()) {
                $item->years()->forYear(2026)?->containsTax(Money::fromCents(7664));
                self::items()->save($item);
            }
        }

        [$first, $second] = [self::unitIds()[0] ?? '', self::unitIds()[1] ?? ''];
        self::aTenancy($first, self::aTenant(self::COMPANY, PartyKind::Company, 'Prüfhandel GmbH'), Taxation::at(1900), $withTerms, $monthlyAdvance);
        self::aTenancy($second, self::aTenant(self::PERSON, PartyKind::Person, 'Mieterin'), Taxation::exempt(), false, 15000);
        self::theOperatingCostPayments($first);
        self::theOperatingCostPayments($second);
    }

    private static function aDraft(): Statement
    {
        $statements = self::getContainer()->get(StatementRepository::class);
        self::assertInstanceOf(StatementRepository::class, $statements);
        $statement = new Statement($statements->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear(2026));
        $statements->save($statement);

        return $statement;
    }

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

    private static function tenantLetterFor(int $unitNumber, Proposal $proposal): ProposedDocument
    {
        foreach ($proposal->documents as $document) {
            if (StatementKind::OperatingCosts === $document->kind && $unitNumber === $document->unitNumber) {
                return $document;
            }
        }

        self::fail('Einheit '.$unitNumber.' bekommt keine Betriebskostenabrechnung.');
    }

    private static function releasedTenantLetterFor(int $unitNumber, Statement $statement): StatementDocument
    {
        foreach ($statement->documents() as $document) {
            if (StatementKind::OperatingCosts === $document->kind() && $unitNumber === $document->unitNumber()) {
                return $document;
            }
        }

        self::fail('Einheit '.$unitNumber.' hat kein freigegebenes Schreiben.');
    }

    /**
     * @return list<\App\Module\Finance\Domain\Due>
     */
    private static function dueFor(string $unitId): array
    {
        $due = self::getContainer()->get(DueAdvances::class);
        self::assertInstanceOf(DueAdvances::class, $due);

        return array_values(array_filter(
            $due->forUnit($unitId, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31')),
            static fn (object $owed): bool => AdvanceKind::OperatingCosts === $owed->kind,
        ));
    }

    private static function theOperatingCostPayments(string $unitId): void
    {
        $payments = [];

        foreach (self::dueFor($unitId) as $owed) {
            $payments[] = new AdvancePayment($unitId, $owed->kind, 2026, $owed->dueOn, $owed->expected);
        }

        self::paid()->saveAll($payments);
    }

    private static function aTenancy(string $unitId, Party $tenant, Taxation $taxation, bool $withTerms, int $monthlyAdvance): void
    {
        $tenancies = self::tenancies();
        $tenancy = new Tenancy($tenancies->nextNumber(), $unitId);
        new Tenant($tenancy, $tenant->id());
        new RentStep($tenancy, new DateTimeImmutable('2026-01-01'), Rent::of(
            Money::fromCents(120000),
            Money::fromCents($monthlyAdvance),
            Money::zero(),
            Money::zero(),
        ));
        $tenancy->runFor(Term::of(new DateTimeImmutable('2026-01-01'), null, null, null));
        $tenancy->taxAs($taxation);

        if ($withTerms) {
            $tenancy->paidBy(Payment::of(PaymentMethod::Transfer, PaymentDue::ThirdWorkingDay, EInvoiceTerms::of(
                'HANDEL-0815',
                Email::fromString('handel-rechnung@example.org'),
                '',
                null,
            )));
        }

        $tenancy->activate();
        $tenancies->save($tenancy);
    }

    private static function aTenant(int $reference, PartyKind $kind, string $name): Party
    {
        $tenant = new Party(
            $reference,
            $kind,
            $name,
            PartyKind::Person === $kind ? 'Paula' : null,
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Handelsweg '.$reference, '40233', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('mieter'.$reference.'@example.org')]),
        );
        self::parties()->save($tenant);

        return $tenant;
    }

    /** Der Eigentuemer stellt die Rechnung — mit Steuernummer. */
    private static function theOwnerHasATaxNumber(): void
    {
        foreach (self::parties()->matching(PartyFilter::none(), Page::of(1, 500)) as $party) {
            if (99001 === $party->reference()) {
                $party->taxedAs(TaxId::of('21/815/08150'));
                self::parties()->save($party);
            }
        }
    }

    private static function eInvoiceOf(KernelBrowser $client, Statement $statement, int $unitNumber): string
    {
        $document = self::releasedTenantLetterFor($unitNumber, $statement);
        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'/dokumente/'.$document->id().'/xrechnung');
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    /** Laufnummer und Mietvertrag vergibt die Datenbank — in der Referenzdatei stehen Platzhalter. */
    private static function normalised(string $xml, Statement $statement): string
    {
        $tenancy = (string) self::releasedTenantLetterFor(1, $statement)->letting()->tenancyNumber();

        return str_replace(
            ['-2026-'.$statement->number().'-', '>'.$tenancy.'<'],
            ['-2026-{LAUF}-', '>{MIETVERTRAG}<'],
            $xml,
        );
    }

    private static function fixture(string $file): string
    {
        return \dirname(__DIR__).'/Fixture/xrechnung/'.$file;
    }

    private static function tenancies(): TenancyRepository
    {
        $found = self::getContainer()->get(TenancyRepository::class);
        self::assertInstanceOf(TenancyRepository::class, $found);

        return $found;
    }

    private static function removeTheTenancies(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();
        $connection->executeStatement(
            'DELETE FROM tenancy WHERE unit_id IN (SELECT id FROM property_unit WHERE property_id = ?)',
            [self::propertyId()],
        );
        $connection->executeStatement('DELETE FROM party WHERE reference IN (?, ?)', [self::COMPANY, self::PERSON]);
    }
}
