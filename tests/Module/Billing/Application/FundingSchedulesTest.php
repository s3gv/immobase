<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Application;

use App\Module\Billing\Application\FundingSchedules;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Wie eine Sonderumlage in Raten zerfaellt.
 *
 * Die Regel ist die einer Verwaltung und nicht die eines Rechners: **alle
 * Raten gleich gross, die letzte traegt den Rest.** Damit laesst sich der
 * Ratenplan in einem Satz sagen, und das Blatt braucht keine Spalte je Termin.
 */
final class FundingSchedulesTest extends TestCase
{
    /** Zwoelf Raten aus einem Betrag, der nicht durch zwoelf geht. */
    public function testEveryInstalmentIsTheSameButTheLast(): void
    {
        $parts = FundingSchedules::instalments(Money::fromCents(100007), 12);

        self::assertCount(12, $parts);

        foreach (\array_slice($parts, 0, 11) as $part) {
            self::assertSame(8333, $part->cents(), 'Elf gleiche Raten');
        }

        self::assertSame(8344, $parts[11]->cents(), 'Und der Rest auf der letzten');
    }

    /** Zusammen sind die Raten der Betrag — auf den Cent, bei jeder Teilung. */
    public function testTheInstalmentsAddUpExactly(): void
    {
        foreach ([1, 2, 3, 7, 12, 24] as $count) {
            foreach ([100000, 100001, 999999, 1] as $cents) {
                $sum = Money::zero();

                foreach (FundingSchedules::instalments(Money::fromCents($cents), $count) as $part) {
                    $sum = $sum->plus($part);
                }

                self::assertSame($cents, $sum->cents(), \sprintf('%d in %d Raten', $cents, $count));
            }
        }
    }

    /** Eine einzige Rate ist der ganze Betrag und keine Rechnung. */
    public function testASingleInstalmentIsTheWholeAmount(): void
    {
        self::assertSame(100007, FundingSchedules::instalments(Money::fromCents(100007), 1)[0]->cents());
    }
}
