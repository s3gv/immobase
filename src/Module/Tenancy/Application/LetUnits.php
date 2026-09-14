<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Property\Contract\UnitLink;
use App\Module\Property\Contract\UnitLinkSource;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Meldet dem Objektmodul, was an einer Einheit haengt.
 *
 * Die erste Umsetzung von UnitLinkSource. Sie bewirkt zweierlei: auf der
 * Einheitenseite steht ein Sprung zum Mietverhaeltnis, und eine Einheit mit
 * Mietverhaeltnis laesst sich nicht mehr loeschen.
 *
 * Auch die inaktiven melden sich. Ein geloeschtes Objekt naehme sonst die
 * Geschichte seiner Einheiten mit, ohne dass jemand gefragt haette.
 *
 * Die Richtung ist Absicht: Property darf dieses Modul nicht kennen, dieses
 * Modul aber Property.
 */
final readonly class LetUnits implements UnitLinkSource
{
    public function __construct(
        private TenancyRepository $tenancies,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function linksTo(array $unitIds): array
    {
        $links = [];

        foreach ($this->tenancies->forUnits($unitIds) as $unitId => $tenancies) {
            $links[$unitId] = array_map($this->link(...), $tenancies);
        }

        return $links;
    }

    private function link(Tenancy $tenancy): UnitLink
    {
        return new UnitLink(
            labelKey: $tenancy->status()->isActive() ? 'tenancy.unit.link' : 'tenancy.unit.link_past',
            text: $this->translator->trans('tenancy.number', ['%number%' => $tenancy->number()]),
            url: $this->urls->generate('app_tenancy_show', ['number' => $tenancy->number()]),
            // Ein beendetes zaehlt bei einer Abwicklung nicht mit.
            countKey: $tenancy->status()->isActive() ? 'tenancy.unit.count' : '',
        );
    }
}
