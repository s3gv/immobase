<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;

/**
 * Was die Darlehen eines Objekts in einem Jahr kosten.
 *
 * **Getrennt, weil es zweierlei ist.** Der Zins ist Aufwand: er ist weg. Die
 * Tilgung schichtet Vermoegen um — das Geld fliesst ab, aber die Schuld
 * sinkt um denselben Betrag. Geplant werden muss trotzdem beides, weil
 * beides abfliesst; ein Wirtschaftsplan, der nur den Zins plant, sammelt zu
 * wenig ein.
 */
final readonly class LoanBurden
{
    public function __construct(
        public Money $interest,
        public Money $principal,
    ) {
    }

    public static function nothing(): self
    {
        return new self(Money::zero(), Money::zero());
    }

    /** Zins und Tilgung zusammen — was im Jahr abfliesst. */
    public function total(): Money
    {
        return $this->interest->plus($this->principal);
    }

    public function isZero(): bool
    {
        return $this->interest->isZero() && $this->principal->isZero();
    }
}
