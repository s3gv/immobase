<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\StepIsTaken;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitHousehold;
use App\Module\Property\Domain\UnitRepository;
use DateTimeImmutable;

/**
 * Die Personenzahl einer Einheit ueber die Zeit pflegen.
 *
 * Dieselbe Form wie die Personenstaffel des Mietverhaeltnisses — ein Wert,
 * der ab einem Tag gilt — und dieselbe Regel: zu einem Tag hoechstens ein
 * Eintrag. Was am Tag X galt, ist damit fuer jeden Tag beantwortbar, auch
 * fuer einen, der zwei Jahre zurueckliegt und gerade abgerechnet wird.
 */
final readonly class ScheduleUnitHousehold
{
    public function __construct(private UnitRepository $units)
    {
    }

    /**
     * @throws StepIsTaken
     */
    public function add(Unit $unit, DateTimeImmutable $startsOn, int $people): UnitHousehold
    {
        self::refuseTaken($unit, $startsOn, null);

        $step = new UnitHousehold($unit, $startsOn, $people);
        $this->units->save($unit);

        return $step;
    }

    /**
     * @throws StepIsTaken
     */
    public function change(UnitHousehold $step, DateTimeImmutable $startsOn, int $people): void
    {
        self::refuseTaken($step->unit(), $startsOn, $step);

        $step->beginsOn($startsOn);
        $step->house($people);
        $this->units->save($step->unit());
    }

    public function drop(UnitHousehold $step): void
    {
        $this->units->removeHousehold($step);
    }

    /**
     * Zwei Eintraege zum selben Tag waeren zwei Antworten auf eine Frage.
     *
     * Die Datenbank besteht ohnehin darauf; hier faellt es mit einem Satz
     * auf, den jemand lesen kann.
     *
     * @throws StepIsTaken
     */
    private static function refuseTaken(Unit $unit, DateTimeImmutable $startsOn, ?UnitHousehold $itself): void
    {
        foreach ($unit->household()->steps() as $step) {
            if ($step !== $itself && $step->startsOn()->format('Y-m-d') === $startsOn->format('Y-m-d')) {
                throw StepIsTaken::onThatDay();
            }
        }
    }
}
