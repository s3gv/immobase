<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Contract\UnitLink;
use App\Module\Property\Contract\UnitLinkSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sammelt, was an einer Einheit haengt.
 *
 * Fragt jedes Modul, das sich als Quelle angemeldet hat. Gibt es keine, ist
 * die Antwort leer — und dann darf geloescht werden.
 */
final readonly class CountUnitLinks
{
    /**
     * @param iterable<UnitLinkSource> $sources
     */
    public function __construct(
        #[AutowireIterator('property.unit_link_source')]
        private iterable $sources,
    ) {
    }

    /**
     * @param list<string> $unitIds
     *
     * @return array<string, list<UnitLink>>
     */
    public function forUnits(array $unitIds): array
    {
        $links = [];

        foreach ($this->sources as $source) {
            foreach ($source->linksTo($unitIds) as $unitId => $found) {
                $links[$unitId] = [...$links[$unitId] ?? [], ...$found];
            }
        }

        return $links;
    }

    /**
     * @return list<UnitLink>
     */
    public function forUnit(string $unitId): array
    {
        return $this->forUnits([$unitId])[$unitId] ?? [];
    }

    public function anyFor(string $unitId): bool
    {
        return [] !== $this->forUnit($unitId);
    }
}
