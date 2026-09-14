<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Hausgeldstufen einer Einheit.
 *
 * Kein Datensatz, sondern ein Blick auf sie — dieselbe Rechnung wie bei der
 * Mietstaffel und an einer Stelle: was heute gilt, ist die letzte Stufe mit
 * Datum <= heute und nicht die letzte ueberhaupt. Eine Erhoehung, die erst
 * naechstes Jahr greift, steht schon da und gilt noch nicht.
 */
final readonly class AdvanceSchedule
{
    /**
     * @param list<HouseMoney> $steps nach Datum aufsteigend
     */
    private function __construct(private array $steps)
    {
    }

    /**
     * @param list<HouseMoney> $steps
     */
    public static function of(array $steps): self
    {
        usort($steps, static fn (HouseMoney $one, HouseMoney $other): int => $one->startsOn() <=> $other->startsOn());

        return new self($steps);
    }

    /** @return list<HouseMoney> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return [] === $this->steps;
    }

    public function on(DateTimeImmutable $day): ?HouseMoney
    {
        $current = null;

        foreach ($this->steps as $step) {
            if ($step->startsOn() > $day) {
                break;
            }

            $current = $step;
        }

        return $current;
    }

    /** Was an diesem Tag zu zahlen ist. Ohne Stufe: nichts vereinbart. */
    public function amountOn(DateTimeImmutable $day): ?Money
    {
        return $this->on($day)?->amount();
    }
}
