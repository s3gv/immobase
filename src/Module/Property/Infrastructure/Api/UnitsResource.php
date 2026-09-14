<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Infrastructure\Api;

use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;

/**
 * Einheiten — die Bezugsgroesse jeder Auswertung ueber Vermietung.
 *
 * Flaeche und Miteigentumsanteil kommen als Zeichenkette mit: beides sind
 * exakte Dezimalzahlen, und als JSON-Zahl waeren sie es nicht mehr.
 */
final readonly class UnitsResource implements PublishesResource
{
    public function __construct(private UnitRepository $units)
    {
    }

    public function name(): string
    {
        return 'units';
    }

    public function permission(): string
    {
        return PropertyPermissions::VIEW;
    }

    public function identifies(object $entity): ?string
    {
        return $entity instanceof Unit ? $entity->id() : null;
    }

    public function page(ApiQuery $query): ApiPage
    {
        $page = Page::of($query->page, $this->units->countManaged());

        return new ApiPage(
            array_map(self::describe(...), $this->units->pageOf($page)),
            $page->number,
            $page->pages,
            $page->total,
        );
    }

    public function one(string $id): ?array
    {
        $found = $this->units->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Unit $unit): array
    {
        return [
            'id' => $unit->id(),
            'property_id' => $unit->property()->id(),
            'number' => $unit->number(),
            'label' => $unit->label(),
            'usage' => $unit->usage()->value,
            'status' => $unit->status()->value,
            'area' => $unit->measures()->area(),
            'rooms' => $unit->measures()->rooms(),
            'mea' => $unit->mea()->toString(),
        ];
    }
}
