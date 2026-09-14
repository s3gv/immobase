<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Figure\ContributesFigures;
use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Was verwaltet wird — Objekte und Einheiten.
 *
 * Ohne die abgegebenen: was nicht mehr verwaltet wird, steht in keiner
 * Abrechnung und in keinem Plan. Eine Zahl, die es mitzaehlte, waere groesser
 * als die Arbeit dahinter.
 */
#[AsTaggedItem(priority: 90)]
final readonly class PropertyFigures implements ContributesFigures
{
    public function __construct(
        private PropertyRepository $properties,
        private UnitRepository $units,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function figures(): array
    {
        if (!$this->mayView->isGranted(PropertyPermissions::VIEW)) {
            return [];
        }

        $url = $this->urls->generate('app_property');

        return [
            new Figure(
                group: FigureGroup::Stock,
                labelKey: 'figure.property.properties',
                value: (string) $this->properties->countMatching(PropertyFilter::none()),
                url: $url,
            ),
            new Figure(
                group: FigureGroup::Stock,
                labelKey: 'figure.property.units',
                value: (string) $this->units->countManaged(),
                url: $url,
            ),
        ];
    }
}
