<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Domain;

use App\Module\Billing\Domain\Weight;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Gewichte ohne Fliesskomma.
 *
 * Der Grund, warum es diese Klasse gibt: `(int) round((float) '0.1' * 1000)`
 * ist genau die Stelle, an der eine Abrechnung um einen Cent danebenliegt.
 */
final class WeightTest extends TestCase
{
    public function testAWholeNumberIsScaled(): void
    {
        self::assertSame(72000, Weight::of('72'));
    }

    public function testTwoDecimalsAreScaled(): void
    {
        self::assertSame(72500, Weight::of('72.50'));
    }

    public function testThreeDecimalsSurviveExactly(): void
    {
        self::assertSame(12345, Weight::of('12.345'));
    }

    /**
     * Der Fall, an dem Fliesskomma scheitert.
     *
     * `0.1 * 1000` ergibt in doppelter Genauigkeit 100.00000000000001.
     */
    public function testATenthIsExact(): void
    {
        self::assertSame(100, Weight::of('0.1'));
    }

    public function testMoreDecimalsAreCutAndNotRounded(): void
    {
        self::assertSame(12345, Weight::of('12.3456789'));
    }

    public function testAnEmptyValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Weight::of('');
    }

    public function testTextIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Weight::of('viel');
    }

    /**
     * Die Zusicherung, an der die ganze Abrechnung haengt.
     *
     * Hundert Euro auf drei gleiche Einheiten sind 33,34 + 33,33 + 33,33 —
     * und nicht dreimal 33,33 mit einem verschwundenen Cent.
     */
    public function testTheSumOfSharesIsExactlyTheTotal(): void
    {
        $total = Money::fromCents(10000);

        $parts = $total->allocate([
            Weight::of('1'),
            Weight::of('1'),
            Weight::of('1'),
        ]);

        self::assertSame([3334, 3333, 3333], array_map(
            static fn (Money $part): int => $part->cents(),
            $parts,
        ));
        self::assertSame(10000, array_sum(array_map(
            static fn (Money $part): int => $part->cents(),
            $parts,
        )));
    }

    /** Und bei krummen Flächen genauso. */
    public function testItAlsoAddsUpWithAwkwardAreas(): void
    {
        $total = Money::fromCents(123457);
        $areas = ['72.50', '38.20', '104.75', '19.99'];

        $parts = $total->allocate(array_map(Weight::of(...), $areas));
        $sum = array_sum(array_map(static fn (Money $part): int => $part->cents(), $parts));

        self::assertSame(123457, $sum, 'Kein Cent verloren, keiner erfunden');
    }
}
