<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use DateTimeImmutable;

/**
 * Die Staffel als Ganzes.
 *
 * Kein Datensatz, sondern ein Blick auf die Stufen: „was gilt an diesem Tag"
 * und „was aendert sich als naechstes". Die Rechnung steht hier einmal und
 * nicht in Uebersicht, Mietseite und Schritt jeweils neu — sie ist unscheinbar
 * genug, um dreimal leicht verschieden auszufallen.
 *
 * Was heute gilt, ist die **letzte Stufe mit Datum <= heute** und nicht die
 * letzte ueberhaupt: eine Erhoehung, die erst naechstes Jahr greift, steht
 * schon in der Liste und gilt noch nicht.
 */
final readonly class RentSchedule
{
    /**
     * @param list<RentStep> $steps nach Datum aufsteigend
     */
    private function __construct(private array $steps)
    {
    }

    /**
     * @param list<RentStep> $steps
     */
    public static function of(array $steps): self
    {
        usort(
            $steps,
            static fn (RentStep $one, RentStep $other): int => $one->startsOn() <=> $other->startsOn(),
        );

        return new self($steps);
    }

    /** @return list<RentStep> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return [] === $this->steps;
    }

    /** Die Stufe, die an diesem Tag gilt — oder keine, wenn es noch keine gibt. */
    public function on(DateTimeImmutable $day): ?RentStep
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

    /** Was an diesem Tag zu zahlen ist. Ohne Stufe: nichts erfasst, also null. */
    public function rentOn(DateTimeImmutable $day): ?Rent
    {
        return $this->on($day)?->rent();
    }

    /** Die naechste Aenderung nach diesem Tag — fuer den Hinweis auf der Mietseite. */
    public function nextAfter(DateTimeImmutable $day): ?RentStep
    {
        foreach ($this->steps as $step) {
            if ($step->startsOn() > $day) {
                return $step;
            }
        }

        return null;
    }
}
