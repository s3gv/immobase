<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;

final class MoneyMismatch extends InvalidArgumentException
{
    public static function negativeFactor(int $factor): self
    {
        return new self(\sprintf('Faktor darf nicht negativ sein, war %d.', $factor));
    }

    public static function tooFewParts(int $parts): self
    {
        return new self(\sprintf('Ein Betrag lässt sich nicht in %d Teile teilen.', $parts));
    }

    public static function emptyRatios(): self
    {
        return new self('Aufteilung braucht mindestens ein Verhältnis.');
    }

    public static function nonPositiveRatioSum(int $sum): self
    {
        return new self(\sprintf('Summe der Verhältnisse muss positiv sein, war %d.', $sum));
    }

    public static function negativeRatio(int $ratio): self
    {
        return new self(\sprintf('Verhältnis darf nicht negativ sein, war %d.', $ratio));
    }
}
