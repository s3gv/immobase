<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\Unit;

/**
 * Was eine Abwicklung mitnimmt.
 *
 * Die Bestaetigung soll zeigen, was gleich passiert, und nicht nur warnen.
 * Gezaehlt wird ueber dieselben Quellen, die schon sagen, ob eine Einheit
 * geloescht werden darf — dieses Modul weiss dabei nicht, wer antwortet, und
 * die Vorschau bleibt vollstaendig, wenn ein weiteres Modul dazukommt.
 */
final readonly class PreviewClosure
{
    public function __construct(private CountUnitLinks $links)
    {
    }

    /**
     * Je Bezeichnung, wie viele es sind: „Mietverhältnis" => 4.
     *
     * @return array<string, int>
     */
    public function forProperty(Property $property): array
    {
        $active = array_values(array_filter(
            $property->units(),
            static fn (Unit $unit): bool => $unit->status()->isActive(),
        ));

        // Nichts zu zaehlen heisst nichts zu zeigen: „0 Einheiten" ist keine
        // Zeile, sondern eine fehlende.
        $counted = [] === $active ? [] : ['property.unit.count' => \count($active)];

        return $counted + $this->countLinks(array_map(
            static fn (Unit $unit): string => $unit->id(),
            $active,
        ));
    }

    /**
     * @return array<string, int>
     */
    public function forUnit(Unit $unit): array
    {
        return $this->countLinks([$unit->id()]);
    }

    /**
     * @param list<string> $unitIds
     *
     * @return array<string, int>
     */
    private function countLinks(array $unitIds): array
    {
        $counted = [];

        foreach ($this->links->forUnits($unitIds) as $links) {
            foreach ($links as $link) {
                if ('' === $link->countKey) {
                    continue;
                }

                $counted[$link->countKey] = ($counted[$link->countKey] ?? 0) + 1;
            }
        }

        return $counted;
    }
}
