<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use DateTimeImmutable;

/**
 * Die Personenzahl ueber die Zeit.
 *
 * Kein Datensatz, sondern ein Blick auf die Stufen — dieselbe Rechnung wie
 * bei der Mietstaffel, und aus demselben Grund an einer Stelle: sie ist
 * unscheinbar genug, um in Uebersicht, Mietseite und Abrechnung dreimal
 * leicht verschieden auszufallen.
 */
final readonly class HouseholdSchedule
{
    /**
     * @param list<HouseholdStep> $steps nach Datum aufsteigend
     */
    private function __construct(private array $steps)
    {
    }

    /**
     * @param list<HouseholdStep> $steps
     */
    public static function of(array $steps): self
    {
        usort(
            $steps,
            static fn (HouseholdStep $one, HouseholdStep $other): int => $one->startsOn() <=> $other->startsOn(),
        );

        return new self($steps);
    }

    /** @return list<HouseholdStep> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return [] === $this->steps;
    }

    /** Die Stufe, die an diesem Tag gilt — oder keine, wenn es noch keine gibt. */
    public function on(DateTimeImmutable $day): ?HouseholdStep
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

    /** Wie viele Personen an diesem Tag zaehlen. Ohne Stufe: nichts erfasst. */
    public function peopleOn(DateTimeImmutable $day): ?int
    {
        return $this->on($day)?->people();
    }

    /** Was heute gilt — fuer die Uebersicht und die Mietseite. */
    public function today(): ?int
    {
        return $this->peopleOn(new DateTimeImmutable('today'));
    }
}
