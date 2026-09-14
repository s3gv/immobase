<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\EInvoice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jede Referenzdatei hat die Gestalt des CII-Schemas — und die Pruefung faengt, wenn nicht.
 */
final class CiiSchemaTest extends TestCase
{
    #[DataProvider('references')]
    public function testEveryReferenceFileMatchesTheSchema(string $file): void
    {
        self::assertSame([], CiiSchema::violations(self::read($file)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function references(): iterable
    {
        $found = glob(\dirname(__DIR__, 2).'/Fixture/xrechnung/*.xml');

        foreach (false === $found ? [] : $found as $path) {
            yield basename($path) => [basename($path)];
        }
    }

    public function testThereAreReferenceFilesAtAll(): void
    {
        self::assertGreaterThanOrEqual(8, iterator_count(self::references()));
    }

    /** Die Reihenfolge ist Teil des Schemas: die Waehrung gehoert hinter den Verwendungszweck. */
    public function testAWrongOrderIsCaught(): void
    {
        $xml = self::read('writer-ueberweisung.xml');
        $swapped = str_replace(
            "<ram:PaymentReference>DM-30001-1-1</ram:PaymentReference>\n      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>",
            "<ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>\n      <ram:PaymentReference>DM-30001-1-1</ram:PaymentReference>",
            $xml,
        );

        self::assertNotSame($xml, $swapped, 'Die Stelle, die vertauscht wird, gibt es');
        self::assertNotSame([], CiiSchema::violations($swapped));
    }

    public function testAnUnknownElementIsCaught(): void
    {
        $xml = str_replace('<ram:BuyerReference>', '<ram:Kaeuferreferenz>x</ram:Kaeuferreferenz><ram:BuyerReference>', self::read('writer-ueberweisung.xml'));

        self::assertNotSame([], CiiSchema::violations($xml));
    }

    private static function read(string $file): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/Fixture/xrechnung/'.$file);
    }
}
