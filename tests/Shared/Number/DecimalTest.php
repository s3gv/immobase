<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Number;

use App\Shared\Number\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dezimalzahlen exakt addieren.
 *
 * Der Grund steht in einem Fehler: die Summe der Anteile wurde in der
 * Vorlage aufaddiert, war damit ein `float`, und PHP machte beim Anzeigen
 * aus 3,5 eine 3 — eine Union `string|int` nimmt lieber die ganze Zahl.
 * Eine falsche Summe unter einem Verteilerschluessel faellt niemandem auf,
 * bis die Abrechnung nicht aufgeht.
 */
final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function sums(): iterable
    {
        yield 'nichts ist null' => [[], '0'];
        yield 'die Summe, an der es auffiel' => [['2.5000', '1.0000'], '3.5'];
        yield 'ohne überflüssige Nullen' => [['1.0000', '1.0000'], '2'];
        yield 'die vierte Stelle zählt' => [['0.0001', '0.0002'], '0.0003'];
        yield 'Überträge stimmen' => [['100.7500', '0.2500'], '101'];
        yield 'ganze Zahlen ohne Punkt' => [['3', '0.25'], '3.25'];
        yield 'negativ' => [['-1.2500', '3'], '1.75'];
        yield 'unter null' => [['1', '-2.5'], '-1.5'];
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('sums')]
    public function testAddsExactly(array $values, string $expected): void
    {
        self::assertSame($expected, Decimal::sum($values));
    }

    /** Was keine Dezimalzahl ist, wird nicht stillschweigend zu null. */
    public function testSomethingThatIsNotANumberIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::sum(['1.0000', 'zwei']);
    }
}
