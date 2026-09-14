<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemYearUnit;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;

/**
 * Was eine Kostenposition zum Anzeigen braucht.
 *
 * Das Objekt steht als Kennung an der Position — hier wird daraus ein Name.
 * Gefragt wird fuer eine ganze Seite auf einmal, nicht je Zeile.
 */
final readonly class CostItemView
{
    public function __construct(
        private PropertyDirectory $properties,
        private UnitDirectory $units,
    ) {
    }

    /**
     * @param list<CostItem> $items
     *
     * @return list<array{item: CostItem, property: PropertyBrief|null}>
     */
    public function rows(array $items): array
    {
        $properties = $this->properties->byIds(array_values(array_unique(
            array_map(static fn (CostItem $item): string => $item->propertyId(), $items),
        )));

        return array_map(static fn (CostItem $item): array => [
            'item' => $item,
            'property' => $properties[$item->propertyId()] ?? null,
        ], $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(CostItem $item): array
    {
        return $this->rows([$item])[0] ?? ['item' => $item, 'property' => null];
    }

    /**
     * Was die Erfassung braucht: die Einheiten des Objekts und, was je Jahr
     * schon erfasst ist.
     *
     * Fuer die ganze Seite auf einmal und nicht je Jahr: die Einheiten sind
     * fuer alle Jahre dieselben, und dreimal dieselbe Frage zu stellen ist
     * genau das Muster, das Listen langsam macht.
     *
     * @return array{units: list<UnitBrief>, recorded: array<string, array<string, CostItemYearUnit>>}
     */
    public function measuring(CostItem $item): array
    {
        $property = $this->properties->byIds([$item->propertyId()])[$item->propertyId()] ?? null;
        $recorded = [];

        foreach ($item->years()->all() as $year) {
            $recorded[$year->id()] = [];

            foreach ($year->units() as $unit) {
                $recorded[$year->id()][$unit->unitId()] = $unit;
            }
        }

        return [
            'units' => null === $property ? [] : $this->units->ofProperty($property->number),
            'recorded' => $recorded,
        ];
    }
}
