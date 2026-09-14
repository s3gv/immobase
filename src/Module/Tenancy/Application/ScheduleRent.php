<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\StepNotAllowed;
use App\Module\Tenancy\Domain\Steps;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use DateTimeImmutable;

/**
 * Die Mietstaffel pflegen.
 *
 * Eine Stufe sagt: ab diesem Tag gilt diese Miete. Zwei Regeln halten die
 * Staffel lesbar — keine Stufe vor dem Mietbeginn, und zu einem Tag hoechstens
 * eine. Ohne die erste gaebe es eine Miete fuer eine Zeit ohne
 * Mietverhaeltnis, ohne die zweite waere nicht entscheidbar, welche gilt.
 */
final readonly class ScheduleRent
{
    private const string WHAT = 'Eine Mietstufe';

    public function __construct(private TenancyRepository $tenancies)
    {
    }

    /**
     * @throws StepNotAllowed
     */
    public function add(Tenancy $tenancy, DateTimeImmutable $startsOn, Rent $rent): RentStep
    {
        $this->refuseImpossible($tenancy, $startsOn, null);

        $step = new RentStep($tenancy, $startsOn, $rent);
        $this->tenancies->save($tenancy);

        return $step;
    }

    /**
     * @throws StepNotAllowed
     */
    public function change(RentStep $step, DateTimeImmutable $startsOn, Rent $rent): void
    {
        $this->refuseImpossible($step->tenancy(), $startsOn, $step);

        $step->moveTo($startsOn);
        $step->charge($rent);
        $this->tenancies->save($step->tenancy());
    }

    public function drop(RentStep $step): void
    {
        $tenancy = $step->tenancy();
        $tenancy->remove($step);
        $this->tenancies->save($tenancy);
    }

    /**
     * @throws StepNotAllowed
     */
    private function refuseImpossible(Tenancy $tenancy, DateTimeImmutable $startsOn, ?RentStep $itself): void
    {
        Steps::refuseImpossible(
            $tenancy->term()->startsOn(),
            $tenancy->schedule()->steps(),
            $startsOn,
            $itself,
            self::WHAT,
        );
    }
}
