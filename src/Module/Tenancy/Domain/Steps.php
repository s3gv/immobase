<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use DateTimeImmutable;

/**
 * Was fuer jede Staffel am Mietverhaeltnis gilt.
 *
 * Mietstufen und Haushaltseintraege sind verschiedene Dinge, tragen aber
 * dieselbe Form — ein Datum, ab dem etwas gilt — und damit dieselben zwei
 * Regeln. Sie stehen hier einmal und nicht in jedem Dienst neu, denn zwei
 * Kopien laufen frueher oder spaeter auseinander.
 */
final class Steps
{
    private function __construct()
    {
    }

    /**
     * @param list<HouseholdStep|RentStep> $steps
     *
     * @throws StepNotAllowed
     */
    public static function refuseImpossible(
        ?DateTimeImmutable $start,
        array $steps,
        DateTimeImmutable $startsOn,
        HouseholdStep|RentStep|null $itself,
        string $what,
    ): void {
        if (null !== $start && $startsOn < $start) {
            throw StepNotAllowed::beforeTheStart($what);
        }

        $day = $startsOn->format('Y-m-d');

        foreach ($steps as $step) {
            // Ueber die Zeichenkette und nicht ueber ===: zwei
            // DateTimeImmutable mit demselben Tag sind zwei Objekte.
            if ($step !== $itself && $step->startsOn()->format('Y-m-d') === $day) {
                throw StepNotAllowed::twiceOnTheSameDay($what);
            }
        }
    }
}
