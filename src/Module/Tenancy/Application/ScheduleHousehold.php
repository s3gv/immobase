<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Domain\HouseholdStep;
use App\Module\Tenancy\Domain\StepNotAllowed;
use App\Module\Tenancy\Domain\Steps;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use DateTimeImmutable;

/**
 * Die Personenzahl ueber die Zeit pflegen.
 *
 * Dieselbe Form wie die Mietstaffel, und dieselben zwei Regeln: kein Eintrag
 * vor dem Mietbeginn, zu einem Tag hoechstens einer. Was am Tag X galt, ist
 * damit fuer jeden Tag beantwortbar — auch fuer einen, der zwei Jahre
 * zurueckliegt und gerade abgerechnet wird.
 */
final readonly class ScheduleHousehold
{
    private const string WHAT = 'Ein Haushaltseintrag';

    public function __construct(private TenancyRepository $tenancies)
    {
    }

    /**
     * @throws StepNotAllowed
     */
    public function add(Tenancy $tenancy, DateTimeImmutable $startsOn, int $people): HouseholdStep
    {
        $this->refuseImpossible($tenancy, $startsOn, null);

        $step = new HouseholdStep($tenancy, $startsOn, $people);
        $this->tenancies->save($tenancy);

        return $step;
    }

    /**
     * @throws StepNotAllowed
     */
    public function change(HouseholdStep $step, DateTimeImmutable $startsOn, int $people): void
    {
        $this->refuseImpossible($step->tenancy(), $startsOn, $step);

        $step->moveTo($startsOn);
        $step->house($people);
        $this->tenancies->save($step->tenancy());
    }

    public function drop(HouseholdStep $step): void
    {
        $tenancy = $step->tenancy();
        $tenancy->remove($step);
        $this->tenancies->save($tenancy);
    }

    /**
     * @throws StepNotAllowed
     */
    private function refuseImpossible(Tenancy $tenancy, DateTimeImmutable $startsOn, ?HouseholdStep $itself): void
    {
        Steps::refuseImpossible(
            $tenancy->term()->startsOn(),
            $tenancy->household()->steps(),
            $startsOn,
            $itself,
            self::WHAT,
        );
    }
}
