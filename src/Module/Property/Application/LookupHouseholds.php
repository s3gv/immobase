<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Contract\HouseholdWindow;
use App\Module\Property\Contract\UnitHouseholds;
use App\Module\Property\Domain\UnitHousehold;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Time\Windows;
use DateTimeImmutable;

/**
 * Die Personenstaffel der Einheiten — fuer fremde Module.
 *
 * Aus Stufen werden Fenster: eine Stufe sagt nur, ab wann sie gilt, und bis
 * wann ergibt sich aus der naechsten. Dieselbe Rechnung wie beim
 * Mietverhaeltnis, und darum derselbe Weg ueber {@see Windows} — drei Kopien
 * dieser Datumsarithmetik liefen frueher oder spaeter auseinander.
 */
final readonly class LookupHouseholds implements UnitHouseholds
{
    public function __construct(private UnitRepository $units)
    {
    }

    public function inPeriod(array $unitIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $found = [];

        foreach ($this->units->byIds($unitIds) as $unit) {
            $windows = Windows::within(
                $unit->household()->steps(),
                static fn (UnitHousehold $step): DateTimeImmutable => $step->startsOn(),
                $from,
                $to,
            );

            if ([] !== $windows) {
                $found[$unit->id()] = array_map(
                    static fn (array $window): HouseholdWindow => new HouseholdWindow(
                        $window['from'],
                        $window['to'],
                        $window['step']->people(),
                    ),
                    $windows,
                );
            }
        }

        return $found;
    }
}
