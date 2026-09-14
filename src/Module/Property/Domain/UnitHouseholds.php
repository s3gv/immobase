<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use DateTimeImmutable;

/**
 * Die Personenzahl einer Einheit ueber die Zeit.
 *
 * Kein Datensatz, sondern ein Blick auf die Stufen — dieselbe Rechnung wie
 * bei der Personenstaffel des Mietverhaeltnisses, und an einer Stelle, weil
 * sie unscheinbar genug ist, um an drei Stellen leicht verschieden
 * auszufallen.
 */
final readonly class UnitHouseholds
{
    /**
     * @param list<UnitHousehold> $steps nach Datum aufsteigend
     */
    private function __construct(private array $steps)
    {
    }

    /**
     * @param list<UnitHousehold> $steps
     */
    public static function of(array $steps): self
    {
        usort(
            $steps,
            static fn (UnitHousehold $one, UnitHousehold $other): int => $one->startsOn() <=> $other->startsOn(),
        );

        return new self($steps);
    }

    /** @return list<UnitHousehold> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return [] === $this->steps;
    }

    /** Die Stufe, die an diesem Tag gilt — oder keine, wenn es noch keine gibt. */
    public function on(DateTimeImmutable $day): ?UnitHousehold
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
}
