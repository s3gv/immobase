<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Domain;

use App\Module\Billing\Domain\Outcome;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Was unter dem Strich steht.
 *
 * Der Fall, an dem es haengt: eine Korrektur weist nur die Differenz aus.
 * Rechnete sie den vollen Betrag noch einmal aus, stuende dieselbe Forderung
 * zweimal in der Welt.
 */
final class OutcomeTest extends TestCase
{
    public function testCostsMinusAdvancesIsTheResult(): void
    {
        $outcome = Outcome::nothing()->of(Money::fromCents(120000), Money::fromCents(96000));

        self::assertSame(24000, $outcome->balance()->cents());
        self::assertFalse($outcome->isCorrection());
    }

    public function testMoreAdvancesThanCostsAreACredit(): void
    {
        $outcome = Outcome::nothing()->of(Money::fromCents(90000), Money::fromCents(96000));

        self::assertTrue($outcome->balance()->isNegative());
    }

    /** Bereits Abgerechnetes wird abgezogen — nur die Differenz bleibt. */
    public function testACorrectionShowsOnlyTheDifference(): void
    {
        $outcome = Outcome::nothing()
            ->of(Money::fromCents(130000), Money::fromCents(96000))
            ->after(Money::fromCents(24000));

        self::assertSame(34000, $outcome->result()->cents(), 'Der volle, richtige Stand');
        self::assertSame(10000, $outcome->balance()->cents(), 'Und nur das fehlt noch');
        self::assertTrue($outcome->isCorrection());
    }

    /** Auch dann, wenn die Korrektur ein Guthaben ergibt. */
    public function testACorrectionCanTurnIntoACredit(): void
    {
        $outcome = Outcome::nothing()
            ->of(Money::fromCents(110000), Money::fromCents(96000))
            ->after(Money::fromCents(24000));

        self::assertSame(-10000, $outcome->balance()->cents());
    }

    /** Ein neuer Stand ändert die bereits abgerechnete Zahl nicht. */
    public function testRecalculatingKeepsWhatWasSettled(): void
    {
        $outcome = Outcome::nothing()
            ->of(Money::fromCents(130000), Money::fromCents(96000))
            ->after(Money::fromCents(24000))
            ->of(Money::fromCents(140000), Money::fromCents(96000));

        self::assertSame(24000, $outcome->settled()?->cents());
        self::assertSame(20000, $outcome->balance()->cents());
    }
}
