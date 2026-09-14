<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\IssueRentInvoice;
use App\Module\Billing\Application\ReviseRentInvoice;
use App\Module\Billing\Application\StartRentInvoice;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Property\Domain\BankAccount;
use App\Module\Property\Domain\Property;
use App\Module\Tenancy\Domain\EInvoiceTerms;
use App\Module\Tenancy\Domain\Payment;
use App\Module\Tenancy\Domain\PaymentDue;
use App\Module\Tenancy\Domain\PaymentMethod;
use App\Module\Tenancy\Domain\Tenancy;
use App\Shared\Bank\Bic;
use App\Shared\Bank\CreditorId;
use App\Shared\Bank\Iban;
use App\Shared\Contact\Email;
use App\Tests\Shared\EInvoice\CiiSchema;
use App\Tests\Shared\EInvoice\XRechnungRules;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Dauermietrechnung als XRechnung — heruntergeladen, wie ein Mensch es tut.
 *
 * Verglichen wird gegen Referenzdateien, in denen nur die Nummer des
 * Mietvertrags ein Platzhalter ist: sie vergibt die Datenbank, und sie waere
 * in jedem Lauf eine andere. Alles andere steht bytegenau fest.
 */
final class RentInvoiceEInvoiceTest extends WebTestCase
{
    use BuildsALetProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheLetProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testATransferInvoiceIsTheReferenceFile(): void
    {
        $client = self::viewer();
        self::buildTheLetProperty();
        $invoice = self::anIssuedInvoice();

        $xml = self::downloaded($client, $invoice);

        self::assertStringEqualsFile(self::fixture('dauermietrechnung.xml'), self::normalised($xml));
        self::assertSame([], (new XRechnungRules())->violations($xml));
        self::assertSame([], CiiSchema::violations($xml));
        self::assertStringContainsString(
            'filename='.$invoice->reference().'.xml',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertStringStartsWith('application/xml', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testADirectDebitInvoiceIsTheReferenceFile(): void
    {
        $client = self::viewer();
        self::buildTheLetProperty();
        self::theTenantPaysByDirectDebit();
        $xml = self::downloaded($client, self::anIssuedInvoice());

        self::assertStringEqualsFile(self::fixture('dauermietrechnung-lastschrift.xml'), self::normalised($xml));
        self::assertSame([], (new XRechnungRules())->violations($xml));
        self::assertSame([], CiiSchema::violations($xml));
    }

    /** Eine Berichtigung ist eine korrigierte Rechnung — mit Verweis auf die, die sie berichtigt. */
    public function testACorrectionReferencesTheInvoiceItCorrects(): void
    {
        $client = self::viewer();
        self::buildTheLetProperty();
        $first = self::anIssuedInvoice();

        $revise = self::getContainer()->get(ReviseRentInvoice::class);
        self::assertInstanceOf(ReviseRentInvoice::class, $revise);
        $correction = $revise->correct($first);
        self::issue()->issue($correction, new DateTimeImmutable('2026-02-10'));

        $xml = self::downloaded($client, $correction);

        self::assertStringEqualsFile(self::fixture('dauermietrechnung-berichtigung.xml'), self::normalised($xml));
        self::assertSame([], (new XRechnungRules())->violations($xml));
        self::assertSame([], CiiSchema::violations($xml));
    }

    /** Steuerfrei gibt es keine E-Rechnung — und keinen Knopf dafür. */
    public function testAnExemptInvoiceHasNoEInvoice(): void
    {
        $client = self::viewer();
        self::buildTheLetProperty(withVat: false);
        $invoice = self::anIssuedInvoice();

        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/xrechnung');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        self::assertSelectorNotExists('a[href$="/xrechnung"]');
    }

    /** Mit Umsatzsteuer steht der Knopf neben dem PDF. */
    public function testTheButtonStandsBesideThePdf(): void
    {
        $client = self::viewer();
        self::buildTheLetProperty();
        $invoice = self::anIssuedInvoice();

        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        self::assertSelectorTextContains('a[href$="/xrechnung"]', 'E-Rechnung (XML)');
    }

    public function testItNeedsTheViewPermission(): void
    {
        $client = self::signedInWith([]);
        self::buildTheLetProperty();
        $invoice = self::anIssuedInvoice();

        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/xrechnung');
        self::assertResponseStatusCodeSame(403);
    }

    protected static function testEmail(): string
    {
        return 'xrechnung-miete@example.org';
    }

    private static function viewer(): KernelBrowser
    {
        return self::signedInWith([BillingPermissions::VIEW]);
    }

    private static function downloaded(KernelBrowser $client, RentInvoice $invoice): string
    {
        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/xrechnung');
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    /** Die Nummer des Mietvertrags vergibt die Datenbank — in der Referenzdatei steht ein Platzhalter. */
    private static function normalised(string $xml): string
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);
        $number = (string) $tenancy->number();

        return str_replace(
            ['DM-'.$number.'-', '>'.$number.'<', 'Mietvertrag '.$number],
            ['DM-{MIETVERTRAG}-', '>{MIETVERTRAG}<', 'Mietvertrag {MIETVERTRAG}'],
            $xml,
        );
    }

    private static function theTenantPaysByDirectDebit(): void
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);
        $tenancy->paidBy(Payment::of(PaymentMethod::DirectDebit, PaymentDue::MonthStart, EInvoiceTerms::of(
            'LADEN-4711',
            Email::fromString('laden-rechnung@example.org'),
            'LADEN-MANDAT-01',
            Iban::fromString('DE89370400440532013000'),
        )));
        self::tenancies()->save($tenancy);

        $property = self::letProperties()->byId(self::letPropertyId());
        self::assertInstanceOf(Property::class, $property);
        $property->accountsAs($property->accounting()->collectedVia(BankAccount::of(
            Iban::fromString('DE02120300000000202051'),
            Bic::fromString('BYLADEM1XXX'),
            'Mietkonto Ladenweg',
            'Mietkonto',
            CreditorId::fromString('DE98ZZZ09999999999'),
        )));
        self::letProperties()->save($property);
    }

    private static function anIssuedInvoice(): RentInvoice
    {
        $start = self::getContainer()->get(StartRentInvoice::class);
        self::assertInstanceOf(StartRentInvoice::class, $start);
        $invoice = $start->forTenancy(self::letTenancyId(), new DateTimeImmutable('2026-01-01'), 'Dauermietrechnung ab 2026');
        self::assertInstanceOf(RentInvoice::class, $invoice);
        self::issue()->issue($invoice, new DateTimeImmutable('2026-01-02'));

        return $invoice;
    }

    private static function issue(): IssueRentInvoice
    {
        $issue = self::getContainer()->get(IssueRentInvoice::class);
        self::assertInstanceOf(IssueRentInvoice::class, $issue);

        return $issue;
    }

    private static function fixture(string $file): string
    {
        return \dirname(__DIR__).'/Fixture/xrechnung/'.$file;
    }
}
