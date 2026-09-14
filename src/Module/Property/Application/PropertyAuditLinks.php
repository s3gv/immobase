<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Audit\LinksToRecords;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Wohin ein protokolliertes Objekt und eine protokollierte Einheit fuehren.
 *
 * Zwei Arten in einer Umsetzung: sie liegen im selben Modul, und die Frage
 * ist dieselbe. Bei der Suche waren es zwei Quellen, weil dort jede Art ihre
 * eigene Gruppe bekommt — hier gibt es keine Gruppen.
 */
final readonly class PropertyAuditLinks implements LinksToRecords
{
    public function __construct(
        private PropertyDirectory $properties,
        private UnitRepository $units,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function handles(): array
    {
        return ['Property', 'Unit'];
    }

    public function urlsFor(string $record, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return 'Property' === $record ? $this->propertyUrls($ids) : $this->unitUrls($ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    private function propertyUrls(array $ids): array
    {
        $urls = [];

        foreach ($this->properties->byIds($ids) as $id => $property) {
            $urls[$id] = $this->urls->generate('app_property_show', ['number' => $property->number]);
        }

        return $urls;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    private function unitUrls(array $ids): array
    {
        $urls = [];

        foreach ($this->units->byIds($ids) as $unit) {
            $urls[$unit->id()] = $this->urls->generate('app_unit_show', [
                'number' => $unit->property()->number(),
                'unit' => $unit->number(),
            ]);
        }

        return $urls;
    }
}
