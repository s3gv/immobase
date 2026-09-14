<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Domain\HouseholdStep;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Holt ein Mietverhaeltnis oder eine seiner Stufen — oder endet mit 404.
 *
 * Als eigener Dienst und nicht als Methode im Controller: dieselben zwei
 * Zeilen stehen sonst in jedem Aufruf, und beim zehnten fehlt die Pruefung.
 */
final readonly class RequireTenancy
{
    public function __construct(private TenancyRepository $tenancies)
    {
    }

    public function __invoke(int $number): Tenancy
    {
        return $this->tenancies->byNumber($number)
            ?? throw new NotFoundHttpException(\sprintf('Kein Mietverhältnis mit der Nummer %d.', $number));
    }

    /**
     * Eine Mietstufe — und zwar eine von diesem Mietverhaeltnis.
     *
     * Ohne die zweite Pruefung liesse sich ueber eine fremde Kennung die
     * Stufe eines anderen Mietverhaeltnisses aendern.
     */
    public function step(Tenancy $tenancy, string $id): RentStep
    {
        foreach ($tenancy->schedule()->steps() as $step) {
            if ($step->id() === $id) {
                return $step;
            }
        }

        throw new NotFoundHttpException(\sprintf('Das Mietverhältnis %d hat diese Mietstufe nicht.', $tenancy->number()));
    }

    /** Ein Haushaltseintrag — und zwar einer von diesem Mietverhaeltnis. */
    public function householdStep(Tenancy $tenancy, string $id): HouseholdStep
    {
        foreach ($tenancy->household()->steps() as $step) {
            if ($step->id() === $id) {
                return $step;
            }
        }

        throw new NotFoundHttpException(\sprintf('Das Mietverhältnis %d hat diesen Haushaltseintrag nicht.', $tenancy->number()));
    }
}
