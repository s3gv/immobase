<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\AdvanceSchedules;
use App\Module\Finance\Contract\PlannedAdvance;
use App\Module\Finance\Domain\HouseMoney;
use App\Module\Finance\Domain\HouseMoneyRepository;

/**
 * Nimmt die beschlossenen Vorschuesse entgegen und legt sie als Staffel ab.
 */
final readonly class ScheduleDecidedAdvances implements AdvanceSchedules
{
    public function __construct(private HouseMoneyRepository $steps)
    {
    }

    public function decided(array $advances): void
    {
        $known = $this->steps->forUnits(array_values(array_map(
            static fn (PlannedAdvance $advance): string => $advance->unitId,
            $advances,
        )));
        $written = [];

        foreach ($advances as $advance) {
            $schedule = $known[$advance->unitId] ?? null;
            $step = self::startingThatDay($schedule?->steps() ?? [], $advance) ?? new HouseMoney(
                $advance->unitId,
                $advance->startsOn,
                $advance->amount,
                $advance->interval,
            );

            $step->charge($advance->amount, $advance->interval);
            $step->decidedBy($advance->reference);
            $written[] = $step;
        }

        // Alle auf einmal: ein Beschluss ist eine Entscheidung und nicht
        // zwoelf. Die Klammer darum setzt der Aufrufer — hier faellt nur die
        // Zahl der Gelegenheiten, auf halbem Weg liegenzubleiben.
        $this->steps->saveAll($written);
    }

    /**
     * Die Stufe, die an diesem Tag schon beginnt.
     *
     * Ueber die Zeichenkette und nicht ueber `===`: zwei DateTimeImmutable mit
     * demselben Tag sind zwei Objekte.
     *
     * @param list<HouseMoney> $steps
     */
    private static function startingThatDay(array $steps, PlannedAdvance $advance): ?HouseMoney
    {
        $day = $advance->startsOn->format('Y-m-d');

        foreach ($steps as $step) {
            if ($step->startsOn()->format('Y-m-d') === $day) {
                return $step;
            }
        }

        return null;
    }
}
