<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Number;

use App\Shared\Number\Decimals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zahlen werden gelesen, wie die Sprache sie schreibt.
 *
 * Der Fall, der diesen Test ausgeloest hat: auf der Einheitenseite stand
 * „78.40 m²" und „3.5 Zimmer" — die Schreibweise der Datenbank, nicht die
 * des Lesers. Das betrifft nicht nur Geld.
 */
final class DecimalsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function numbers(): iterable
    {
        yield 'Flaeche auf Deutsch' => ['78.40', 'de', '78,40'];
        yield 'Flaeche auf Englisch' => ['78.40', 'en', '78.40'];
        yield 'Tausender auf Deutsch' => ['1240.00', 'de', '1.240,00'];
        yield 'Tausender auf Englisch' => ['1240.00', 'en', '1,240.00'];
        yield 'Millionen' => ['1234567.89', 'de', '1.234.567,89'];
        yield 'ohne Nachkomma' => ['3', 'de', '3'];
        yield 'eine Nachkommastelle' => ['3.5', 'de', '3,5'];
        yield 'negativ' => ['-1240.50', 'de', '-1.240,50'];
        yield 'unbekannte Sprache faellt auf Deutsch' => ['1240.00', 'fr', '1.240,00'];
    }

    #[DataProvider('numbers')]
    public function testWritesTheNumberForTheLanguage(string $value, string $locale, string $expected): void
    {
        self::assertSame($expected, Decimals::format($value, $locale));
    }

    public function testLeavesTheThousandsAloneWhereTheyAreNotAQuantity(): void
    {
        // Ein Nenner ist eine Skala: „225,5/1.000" laese sich wie ein Fehler.
        self::assertSame('1000', Decimals::format('1000', 'de', false));
        self::assertSame('225,5', Decimals::format('225.5', 'de', false));
    }

    public function testLeavesSomethingItDidNotWriteAlone(): void
    {
        // Lieber das Rohe als eine stillschweigend verfaelschte Zahl.
        self::assertSame('viel', Decimals::format('viel', 'de'));
        self::assertSame('', Decimals::format('', 'de'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function inputs(): iterable
    {
        yield 'deutsch getippt' => ['1.240,50', '1240.50'];
        yield 'englisch getippt' => ['1,240.50', '1240.50'];
        yield 'nur Komma' => ['72,5', '72.5'];
        yield 'nur Punkt, zwei Stellen: Dezimaltrenner' => ['1.24', '1.24'];
        yield 'nur Punkt, drei Stellen: Tausender' => ['1.240', '1240'];
        yield 'nur Komma, drei Stellen: Tausender' => ['1,240', '1240'];
        yield 'nur Punkte, mehrere Gruppen' => ['1.234.567', '1234567'];
        yield 'mit Leerzeichen' => [' 1 240,50 ', '1240.50'];
        yield 'mehrere Tausendergruppen' => ['1.234.567,89', '1234567.89'];
        yield 'ganze Zahl' => ['612', '612'];
        yield 'leer' => ['', ''];
    }

    #[DataProvider('inputs')]
    public function testReadsWhatSomeoneTyped(string $input, string $expected): void
    {
        self::assertSame($expected, Decimals::normalise($input));
    }

    /**
     * Wo es drei Nachkommastellen gibt, ist ein einzelnes Zeichen kein
     * Tausendertrenner.
     *
     * „84,250" ist der Zaehlerstand 84,25 und nicht vierundachtzigtausend.
     * Ein Faktor 1000 in einer Abrechnung faellt niemandem auf.
     */
    public function testAThirdDecimalCanCountWhereItExists(): void
    {
        self::assertSame('84250', Decimals::normalise('84,250'));
        self::assertSame('84.250', Decimals::normalise('84,250', thirdDecimalCounts: true));
    }

    /** Stehen beide Zeichen da, bleibt es beim hinteren. */
    public function testTwoSeparatorsStayUnambiguous(): void
    {
        self::assertSame('1240.500', Decimals::normalise('1.240,500', thirdDecimalCounts: true));
    }
}
