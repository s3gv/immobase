<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Domain;

use App\Module\Tenancy\Domain\Rent;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die vier Positionen und ihre Summen.
 */
final class RentTest extends TestCase
{
    public function testTheTotalIsTheSumOfAllFour(): void
    {
        $rent = self::rent(65000, 12000, 8000, 4500);

        self::assertSame(89500, $rent->total()->cents());
    }

    /**
     * Die Warmmiete laesst den Stellplatz aus.
     *
     * Er wird anders umgelegt und gehoert deshalb nicht in die Zahl, die man
     * mit anderen Wohnungen vergleicht.
     */
    public function testTheWarmRentLeavesTheParkingSpaceOut(): void
    {
        $rent = self::rent(65000, 12000, 8000, 4500);

        self::assertSame(85000, $rent->warm()->cents());
    }

    public function testNothingIsZero(): void
    {
        self::assertTrue(Rent::nothing()->isZero());
        self::assertSame(0, Rent::nothing()->total()->cents());
    }

    /** Gerechnet wird in Cent: 0,10 + 0,20 sind hier 0,30 und nicht 0,30000000000000004. */
    public function testItAddsInCentsAndNotInFloatingPoint(): void
    {
        self::assertSame(30, self::rent(10, 20, 0, 0)->total()->cents());
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function negatives(): iterable
    {
        yield 'Kaltmiete' => [-80000, 0, 0, 0];
        yield 'Betriebskosten' => [65000, -12000, 0, 0];
        yield 'Heizkosten' => [65000, 0, -8000, 0];
        yield 'Stellplatz' => [65000, 0, 0, -4500];
    }

    /**
     * Keine Position ist negativ.
     *
     * MoneyInput laesst ein Vorzeichen zu, und das ist dort richtig — eine
     * Buchung kann negativ sein. Eine Miete nicht: „minus 800 Euro
     * Kaltmiete" waere keine Gutschrift, sondern ein Vertipper, und er wuerde
     * jede Summe still verfaelschen.
     */
    #[DataProvider('negatives')]
    public function testNoPositionCanBeNegative(int $base, int $operating, int $heating, int $parking): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::rent($base, $operating, $heating, $parking);
    }

    /** Auch dann nicht, wenn die Summe stimmt. */
    public function testNotEvenWhenTheTotalWouldBeRight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::rent(100000, -35000, 0, 0);
    }

    private static function rent(int $base, int $operating, int $heating, int $parking): Rent
    {
        return Rent::of(
            Money::fromCents($base),
            Money::fromCents($operating),
            Money::fromCents($heating),
            Money::fromCents($parking),
        );
    }
}
