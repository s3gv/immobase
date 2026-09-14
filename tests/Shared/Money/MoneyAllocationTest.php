<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Money;

use App\Shared\Money\Money;
use App\Shared\Money\MoneyMismatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyAllocationTest extends TestCase
{
    /**
     * @param list<int> $ratios
     * @param list<int> $expectedCents
     */
    #[DataProvider('allocations')]
    public function testAllocatesWithoutLosingCents(int $cents, array $ratios, array $expectedCents): void
    {
        $parts = Money::fromCents($cents)->allocate($ratios);

        $actual = array_map(static fn (Money $m): int => $m->cents(), $parts);

        self::assertSame($expectedCents, $actual);
        self::assertSame($cents, array_sum($actual), 'Summe der Teile muss dem Ganzen entsprechen');
    }

    /**
     * @return iterable<string, array{int, list<int>, list<int>}>
     */
    public static function allocations(): iterable
    {
        yield 'geht glatt auf' => [1000, [1, 1], [500, 500]];
        yield 'ein Cent Rest geht an den ersten' => [100, [1, 1, 1], [34, 33, 33]];
        yield 'zwei Cent Rest gehen an die ersten beiden' => [1001, [1, 1, 1], [334, 334, 333]];
        yield 'ungleiche Verhaeltnisse' => [10000, [7000, 3000], [7000, 3000]];
        yield 'ein einziger Empfaenger bekommt alles' => [777, [1], [777]];
        yield 'null Euro bleibt null' => [0, [1, 2, 3], [0, 0, 0]];
        yield 'ein Verhaeltnis von null bekommt nichts' => [100, [1, 0, 1], [50, 0, 50]];
        yield 'negativer Betrag verliert ebenfalls keinen Cent' => [-100, [1, 1, 1], [-34, -33, -33]];
    }

    public function testThrowsWhenRatiosAreEmpty(): void
    {
        $this->expectException(MoneyMismatch::class);

        Money::fromCents(100)->allocate([]);
    }

    public function testThrowsWhenRatioSumIsZero(): void
    {
        $this->expectException(MoneyMismatch::class);
        $this->expectExceptionMessageIsOrContains('Summe der Verhältnisse muss positiv sein');

        Money::fromCents(100)->allocate([0, 0]);
    }

    public function testThrowsOnNegativeRatio(): void
    {
        $this->expectException(MoneyMismatch::class);
        $this->expectExceptionMessageIsOrContains('Verhältnis darf nicht negativ sein');

        Money::fromCents(100)->allocate([1, -1]);
    }
}
