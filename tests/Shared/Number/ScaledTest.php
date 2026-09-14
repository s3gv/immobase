<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Number;

use App\Shared\Number\Scaled;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aus einer Skala wird wieder eine Zahl.
 *
 * Der Fall, der diesen Test ausgeloest hat: ein bereits vom Dienstleister
 * verteilter Betrag ging als Verteilerschluessel per `$cents / 100` durch
 * Fliesskomma. Ein Cent, den es nicht gibt, verteilt sich von dort weiter.
 */
final class ScaledTest extends TestCase
{
    /**
     * Eine skalierte Ganzzahl wird zur Dezimalzahl — bei jeder Skala.
     *
     * Zwei Stellen sind Cent, drei sind das Gewicht eines
     * Verteilerschluessels. Der Weg ueber `/ 10 ** n` waere Fliesskomma und
     * verloere jenseits von neun Billiarden die letzte Stelle.
     */
    #[DataProvider('scaled')]
    public function testWritesAScaledWholeNumberAsADecimal(int $value, int $decimals, string $expected): void
    {
        self::assertSame($expected, Scaled::asDecimal($value, $decimals));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function scaled(): iterable
    {
        yield 'Cent' => [124050, 2, '1240.50'];
        yield 'Tausendstel' => [78400, 3, '78.400'];
        yield 'ohne Nachkommastellen' => [78, 0, '78'];
        yield 'nur Nachkommastellen' => [5, 3, '0.005'];
        yield 'negativ' => [-5, 3, '-0.005'];
        yield 'jenseits der Fliesskommagenauigkeit' => [9007199254740993, 2, '90071992547409.93'];
    }

    public function testAScaleBeyondTheWholeNumbersIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Scaled::asDecimal(1, 19);
    }
}
