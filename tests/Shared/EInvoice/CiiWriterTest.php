<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\EInvoice;

use App\Shared\EInvoice\CiiWriter;
use App\Shared\EInvoice\EInvoice;
use App\Shared\EInvoice\EInvoiceLine;
use App\Shared\EInvoice\EInvoiceParty;
use App\Shared\EInvoice\EInvoicePayment;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der CII-Schreiber gegen Referenzdateien.
 *
 * Drei Faelle, die zusammen jeden Zweig beruehren: Ueberweisung mit einem
 * Zahlungsempfaenger, der nicht der Verkaeufer ist; Lastschrift; eine
 * korrigierte Rechnung mit Vorauszahlungen, die mehr sind als die Rechnung.
 *
 * **Bytegenau.** Aendert sich eine Datei, ist das eine Aenderung an dem, was
 * beim Mieter ankommt — sie wird angesehen und nicht einfach neu erzeugt.
 */
final class CiiWriterTest extends TestCase
{
    #[DataProvider('invoices')]
    public function testTheOutputIsTheReferenceFile(string $file, EInvoice $invoice): void
    {
        self::assertStringEqualsFile(self::fixture($file), (new CiiWriter())->write($invoice));
    }

    #[DataProvider('invoices')]
    public function testTheReferenceFileBreaksNoRule(string $file, EInvoice $invoice): void
    {
        self::assertSame($invoice->number, self::numberIn($file), 'Die Datei gehört zu diesem Fall');
        self::assertSame([], (new XRechnungRules())->violations((string) file_get_contents(self::fixture($file))));
    }

    /**
     * @return iterable<string, array{string, EInvoice}>
     */
    public static function invoices(): iterable
    {
        yield 'Überweisung' => ['writer-ueberweisung.xml', self::aTransfer()];
        yield 'Lastschrift' => ['writer-lastschrift.xml', self::aDirectDebit()];
        yield 'Korrektur mit Guthaben' => ['writer-korrektur-guthaben.xml', self::aCorrectionWithCredit()];
    }

    public function testTheTaxNumberDecidesItsScheme(): void
    {
        self::assertSame('VA', self::party('DE 123 456 789')->taxScheme());
        self::assertSame('DE123456789', self::party('DE 123 456 789')->normalisedTaxNumber());
        self::assertSame('FC', self::party('133/5711/0815')->taxScheme());
    }

    public function testARateIsWrittenWithoutTrailingZeros(): void
    {
        self::assertSame('19', CiiWriter::percent(1900));
        self::assertSame('7.5', CiiWriter::percent(750));
        self::assertSame('10.25', CiiWriter::percent(1025));
    }

    private static function aTransfer(): EInvoice
    {
        return new EInvoice(
            number: 'DM-30001-1-1',
            issuedOn: new DateTimeImmutable('2026-01-02'),
            seller: new EInvoiceParty('Viktor Vermieter', 'Eigentümerallee 1', '40233', 'Düsseldorf', 'verwaltung@example.org', 'DE 123 456 789'),
            buyer: new EInvoiceParty('Ladenbetrieb GmbH & Co. KG', 'Geschäftsweg 7', '40213', 'Düsseldorf', 'laden-rechnung@example.org'),
            contactName: 'Prüfverwaltung GmbH',
            contactPhone: '0211 4711 00',
            contactEmail: 'verwaltung@example.org',
            buyerReference: 'LADEN-4711',
            contractReference: '30001',
            periodFrom: new DateTimeImmutable('2026-01-01'),
            periodTo: null,
            lines: [
                new EInvoiceLine('Nettomiete', Money::fromCents(180000), EInvoiceLine::MONTH),
                new EInvoiceLine('Betriebskosten-Vorauszahlung', Money::fromCents(35000), EInvoiceLine::MONTH),
                new EInvoiceLine('Heizkosten-Vorauszahlung', Money::fromCents(15000), EInvoiceLine::MONTH),
            ],
            rateBps: 1900,
            payment: EInvoicePayment::transfer('Zahlbar monatlich im Voraus, spätestens am dritten Werktag des Monats.', 'DE02 1203 0000 0000 2020 51', 'Mietkonto Ladenweg'),
            notes: ['Dauerrechnung: gilt monatlich ab 01.01.2026 bis auf Weiteres.', 'Der Vermieter optiert zur Umsatzsteuer (§ 9 UStG).'],
            payeeName: 'Mietkonto Ladenweg',
        );
    }

    private static function aDirectDebit(): EInvoice
    {
        return new EInvoice(
            number: 'DM-30002-1-1',
            issuedOn: new DateTimeImmutable('2026-03-01'),
            seller: new EInvoiceParty('Viktor Vermieter und Vera Vermieterin', 'Eigentümerallee 1', '40233', 'Düsseldorf', 'verwaltung@example.org', '133/5711/0815'),
            buyer: new EInvoiceParty('Praxis Dr. Muster', 'Ärzteweg 3', '40210', 'Düsseldorf', 'praxis@example.org'),
            contactName: 'Prüfverwaltung GmbH',
            contactPhone: '0211 4711 00',
            contactEmail: 'verwaltung@example.org',
            buyerReference: 'PRAXIS-2026',
            contractReference: '30002',
            periodFrom: new DateTimeImmutable('2026-03-01'),
            periodTo: new DateTimeImmutable('2026-12-31'),
            lines: [new EInvoiceLine('Nettomiete', Money::fromCents(95050), EInvoiceLine::MONTH)],
            rateBps: 1900,
            payment: EInvoicePayment::directDebit('Zahlbar monatlich im Voraus zum Monatsanfang.', 'MANDAT-2026/001', 'DE98ZZZ09999999999', 'DE89 3704 0044 0532 0130 00'),
        );
    }

    private static function aCorrectionWithCredit(): EInvoice
    {
        return new EInvoice(
            number: 'NK-20001/1-2026-7-2',
            issuedOn: new DateTimeImmutable('2027-04-01'),
            seller: new EInvoiceParty('Paula Prüfer', 'Kontrollweg 9', '40233', 'Düsseldorf', 'verwaltung@example.org', '21/815/08150'),
            buyer: new EInvoiceParty('Prüfhandel GmbH', 'Handelsweg 1', '40233', 'Düsseldorf', 'handel-rechnung@example.org'),
            contactName: 'Prüfverwaltung GmbH',
            contactPhone: '0211 4711 00',
            contactEmail: 'verwaltung@example.org',
            buyerReference: 'HANDEL-0815',
            contractReference: '30003',
            periodFrom: new DateTimeImmutable('2026-01-01'),
            periodTo: new DateTimeImmutable('2026-12-31'),
            lines: [
                new EInvoiceLine('Grundsteuer', Money::fromCents(68202)),
                new EInvoiceLine('Hauswart', Money::fromCents(20168)),
            ],
            rateBps: 1900,
            payment: EInvoicePayment::notDefined('Das Guthaben wird erstattet.'),
            notes: ['Vereinnahmte Vorauszahlungen 2.142,00 €, darin Umsatzsteuer 19 %: 342,00 €.'],
            precedingNumber: 'NK-20001/1-2026-7-1',
            prepaid: Money::fromCents(214200),
        );
    }

    private static function numberIn(string $file): string
    {
        $xml = simplexml_load_file(self::fixture($file));
        self::assertNotFalse($xml);
        $found = $xml->xpath('//*[local-name()="ExchangedDocument"]/*[local-name()="ID"]');

        return \is_array($found) && isset($found[0]) ? (string) $found[0] : '';
    }

    private static function party(string $taxNumber): EInvoiceParty
    {
        return new EInvoiceParty('Name', 'Weg 1', '12345', 'Ort', 'a@example.org', $taxNumber);
    }

    private static function fixture(string $file): string
    {
        return \dirname(__DIR__, 2).'/Fixture/xrechnung/'.$file;
    }
}
