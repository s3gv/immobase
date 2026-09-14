<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;

/**
 * Was fremde Module ueber Einheiten erfahren.
 *
 * Die Umsetzung von UnitDirectory. Sie uebersetzt die Entity in das schmale
 * Wertobjekt des Contracts — mehr sieht draussen niemand.
 */
final readonly class LookupUnits implements UnitDirectory
{
    /** Genug fuer eine Liste je Einheit. Waechst es darueber, wird geblaettert. */
    private const int MAX_LISTED = 500;

    private const int MAX_RESULTS = 25;

    public function __construct(
        private UnitRepository $units,
        private PropertyRepository $properties,
    ) {
    }

    public function byIds(array $ids): array
    {
        $found = [];

        foreach ($this->units->byIds($ids) as $unit) {
            $found[$unit->id()] = self::brief($unit);
        }

        return $found;
    }

    public function search(string $term, int $limit = 10): array
    {
        $trimmed = trim($term);

        // Ohne Eingabe keine Liste: die ersten zehn Einheiten ohne Bezug zur
        // Frage sind keine Hilfe, sondern eine Falle fuer den schnellen Klick.
        if ('' === $trimmed) {
            return [];
        }

        return array_map(
            self::brief(...),
            $this->units->search($trimmed, min($limit, self::MAX_RESULTS)),
        );
    }

    public function ofProperty(int $propertyNumber): array
    {
        $property = $this->properties->byNumber($propertyNumber);

        return null === $property ? [] : array_map(self::brief(...), $property->units());
    }

    public function all(int $limit = 500): array
    {
        return array_map(self::brief(...), $this->units->all(min($limit, self::MAX_LISTED)));
    }

    private static function brief(Unit $unit): UnitBrief
    {
        $property = $unit->property();

        return new UnitBrief(
            id: $unit->id(),
            number: $unit->number(),
            label: $unit->label(),
            propertyId: $property->id(),
            propertyNumber: $property->number(),
            propertyName: $property->name(),
            address: $property->address()->oneLine(),
            area: $unit->measures()->area(),
            mea: $unit->mea()->numerator(),
            usage: $unit->usage()->value,
        );
    }
}
