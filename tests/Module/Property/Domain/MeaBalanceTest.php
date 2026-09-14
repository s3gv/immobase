<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Domain;

use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\MeaBalance;
use PHPUnit\Framework\TestCase;

/**
 * Die Summenkontrolle.
 *
 * Sie blockiert nichts — Einheiten werden ueber Wochen erfasst. Sie muss
 * deshalb drei Zustaende auseinanderhalten koennen: noch nicht angefangen,
 * unvollstaendig, zu viel. Nur der erste ist unauffaellig.
 */
final class MeaBalanceTest extends TestCase
{
    public function testNothingDistributedIsNotYetAProblem(): void
    {
        $balance = MeaBalance::of(Mea::none(1000), Mea::whole(1000));

        self::assertTrue($balance->isUntouched());
        self::assertFalse($balance->isComplete());
    }

    public function testCompleteWhenTheSumMatches(): void
    {
        $balance = MeaBalance::of(Mea::of('1000', 1000), Mea::whole(1000));

        self::assertTrue($balance->isComplete());
        self::assertTrue($balance->missing()->isZero());
    }

    public function testNamesWhatIsMissing(): void
    {
        $balance = MeaBalance::of(Mea::of('940', 1000), Mea::whole(1000));

        self::assertTrue($balance->isShort());
        self::assertSame('60/1000', $balance->missing()->toString());
    }

    /** Zu viel ist der gefaehrlichere Fall: er sieht nach „fertig" aus. */
    public function testNamesWhatIsTooMuch(): void
    {
        $balance = MeaBalance::of(Mea::of('1010', 1000), Mea::whole(1000));

        self::assertFalse($balance->isComplete());
        self::assertFalse($balance->isShort());
        self::assertSame('10/1000', $balance->excess()->toString());
    }
}
