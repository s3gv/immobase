<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Money;

use App\Shared\Money\Money;
use App\Shared\Money\MoneyMismatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testStoresCentsExactly(): void
    {
        self::assertSame(1050, Money::fromCents(1050)->cents());
    }

    /**
     * Der Betrag als Dezimalzahl — auch dort, wo Fliesskomma aufgibt.
     *
     * `$cents / 100` ist eine Division und damit ein Fliesskommawert. Bis
     * etwa neun Billiarden geht das gut, darueber nicht mehr: aus
     * 9007199254740993 Cent wuerde so „90071992547409.92" statt „…93". Ein
     * Cent, den es nicht gibt — und wenn die Zahl ein Verteilerschluessel
     * ist, verteilt er sich weiter.
     */
    #[DataProvider('amounts')]
    public function testWritesItselfAsADecimalWithoutFloat(int $cents, string $expected): void
    {
        self::assertSame($expected, Money::fromCents($cents)->toDecimal());
    }

    /** @return iterable<string, array{int, string}> */
    public static function amounts(): iterable
    {
        yield 'null' => [0, '0.00'];
        yield 'unter einem Euro' => [7, '0.07'];
        yield 'mit Zehnern' => [70, '0.70'];
        yield 'der übliche Fall' => [124050, '1240.50'];
        yield 'negativ' => [-124050, '-1240.50'];
        yield 'negativ unter einem Euro' => [-7, '-0.07'];
        // Die kleinste ganze Zahl, die als Fliesskomma nicht mehr genau ist.
        yield 'jenseits der Fliesskommagenauigkeit' => [9007199254740993, '90071992547409.93'];
        yield 'und negativ' => [-9007199254740993, '-90071992547409.93'];
        yield 'der größte Betrag' => [\PHP_INT_MAX, '92233720368547758.07'];
        yield 'der kleinste Betrag' => [\PHP_INT_MIN, '-92233720368547758.08'];
    }

    /**
     * Ein Steuerbetrag wird kaufmaennisch gerundet — und eine Gutschrift traegt dieselbe Steuer.
     */
    #[DataProvider('shares')]
    public function testTakesAShareInBasisPoints(int $cents, int $points, int $expected): void
    {
        self::assertSame($expected, Money::fromCents($cents)->basisPoints($points)->cents());
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function shares(): iterable
    {
        yield '19 % glatt' => [100000, 1900, 19000];
        yield 'halber Cent rundet auf' => [150, 1900, 29];
        yield 'knapp darunter rundet ab' => [131, 1900, 25];
        yield '7 %' => [1000, 700, 70];
        yield 'nichts' => [0, 1900, 0];
        yield 'Gutschrift spiegelbildlich' => [-150, 1900, -29];
    }

    public function testZeroIsZeroCents(): void
    {
        self::assertSame(0, Money::zero()->cents());
    }

    public function testAddsTwoAmounts(): void
    {
        $sum = Money::fromCents(1050)->plus(Money::fromCents(295));

        self::assertSame(1345, $sum->cents());
    }

    public function testSubtractsAndAllowsNegativeResult(): void
    {
        $difference = Money::fromCents(500)->minus(Money::fromCents(800));

        self::assertSame(-300, $difference->cents());
    }

    public function testMultipliesByWholeFactor(): void
    {
        self::assertSame(3150, Money::fromCents(1050)->multipliedBy(3)->cents());
    }

    public function testIsImmutable(): void
    {
        $original = Money::fromCents(1000);
        $original->plus(Money::fromCents(500));

        self::assertSame(1000, $original->cents());
    }

    public function testEqualityComparesAmounts(): void
    {
        self::assertTrue(Money::fromCents(500)->equals(Money::fromCents(500)));
        self::assertFalse(Money::fromCents(500)->equals(Money::fromCents(501)));
    }

    public function testThrowsWhenMultiplyingByNegativeFactor(): void
    {
        $this->expectException(MoneyMismatch::class);
        $this->expectExceptionMessageIsOrContains('Faktor darf nicht negativ sein');

        Money::fromCents(100)->multipliedBy(-1);
    }

    /**
     * Eine wiederkehrende Zahlung ist jeden Monat derselbe Betrag.
     *
     * `allocate()` loest das anders und richtig — dort sind die Teile
     * verschieden gross und die Summe stimmt exakt. Ein Dauerauftrag kann das
     * nicht: er braucht eine Zahl, und die wird aufgerundet, damit die
     * Gemeinschaft nicht zu wenig einnimmt.
     */
    public function testSplitsIntoEqualRecurringParts(): void
    {
        self::assertSame(10000, Money::fromCents(120000)->eachOf(12)->cents(), 'Was aufgeht, geht auf');
        self::assertSame(21354, Money::fromCents(256247)->eachOf(12)->cents(), '21353,92 wird zu 21354');
        self::assertSame(1, Money::fromCents(1)->eachOf(12)->cents(), 'Ein Cent bleibt ein Cent');
        self::assertSame(0, Money::zero()->eachOf(12)->cents());
    }

    /** Zwoelf Raten decken den Jahresanteil immer ab. */
    public function testTwelvePartsNeverFallShortOfTheWhole(): void
    {
        foreach (range(0, 200) as $cents) {
            self::assertGreaterThanOrEqual(
                $cents,
                Money::fromCents($cents)->eachOf(12)->multipliedBy(12)->cents(),
                $cents.' Cent in zwölf Raten',
            );
        }
    }

    /** Das Vorzeichen bleibt, und gerundet wird vom Betrag weg. */
    public function testItKeepsTheSignWhenSplitting(): void
    {
        self::assertSame(-21354, Money::fromCents(-256247)->eachOf(12)->cents());
    }

    public function testThrowsWhenSplittingIntoTooFewParts(): void
    {
        $this->expectException(MoneyMismatch::class);
        $this->expectExceptionMessageIsOrContains('lässt sich nicht in 0 Teile teilen');

        Money::fromCents(100)->eachOf(0);
    }
}
