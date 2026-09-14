<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Infrastructure\Api;

use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;

/**
 * Objekte, wie ein Plugin sie sieht.
 *
 * **Nicht die Entity und nicht alles, was sie weiss.** Was hier steht, ist
 * eine Zusage an Dritte: diese Felder bleiben in v1. Eine Notiz oder der
 * Grundbuchstand gehoeren nicht dazu — sie sind Arbeitsmittel der Verwaltung,
 * nicht Auskunft ueber das Objekt.
 */
final readonly class PropertiesResource implements PublishesResource
{
    public function __construct(private PropertyRepository $properties)
    {
    }

    public function name(): string
    {
        return 'properties';
    }

    public function permission(): string
    {
        return PropertyPermissions::VIEW;
    }

    public function identifies(object $entity): ?string
    {
        return $entity instanceof Property ? $entity->id() : null;
    }

    public function page(ApiQuery $query): ApiPage
    {
        $filter = PropertyFilter::none();
        $page = Page::of($query->page, $this->properties->countMatching($filter));

        return new ApiPage(
            array_map(self::describe(...), $this->properties->matching($filter, $page)),
            $page->number,
            $page->pages,
            $page->total,
        );
    }

    public function one(string $id): ?array
    {
        $found = $this->properties->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Property $property): array
    {
        $address = $property->address();

        return [
            'id' => $property->id(),
            'number' => $property->number(),
            'name' => $property->name(),
            'status' => $property->status()->value,
            'address' => [
                'street' => $address->street(),
                'postal_code' => $address->postalCode(),
                'city' => $address->city(),
            ],
            'units' => \count($property->units()),
        ];
    }
}
