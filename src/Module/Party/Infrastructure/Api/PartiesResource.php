<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Infrastructure\Api;

use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Party\Domain\PartyRepository;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;

/**
 * Kontakte und Parteien, wie ein Plugin sie sieht.
 *
 * **Mit Anschrift, ohne Mailadresse und Telefonnummer.** Eine Auswertung
 * braucht zu wissen, wo jemand wohnt; sie braucht keinen Weg, ihn
 * anzuschreiben. Was eine Auswertung nicht braucht, geht auch nicht ueber die
 * Grenze — hinterher laesst sich eine Zusage nur schwer wieder einsammeln.
 */
final readonly class PartiesResource implements PublishesResource
{
    public function __construct(private PartyRepository $parties)
    {
    }

    public function name(): string
    {
        return 'parties';
    }

    public function permission(): string
    {
        return PartyPermissions::VIEW;
    }

    public function identifies(object $entity): ?string
    {
        return $entity instanceof Party ? $entity->id() : null;
    }

    public function page(ApiQuery $query): ApiPage
    {
        $filter = PartyFilter::none();
        $page = Page::of($query->page, $this->parties->countMatching($filter));

        return new ApiPage(
            array_map(self::describe(...), $this->parties->matching($filter, $page)),
            $page->number,
            $page->pages,
            $page->total,
        );
    }

    public function one(string $id): ?array
    {
        $found = $this->parties->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Party $party): array
    {
        $address = $party->addresses()->primary();

        return [
            'id' => $party->id(),
            'reference' => $party->reference(),
            'name' => $party->displayName(),
            'kind' => $party->kind()->value,
            'status' => $party->status()->value,
            'address' => [
                'line' => $address->line,
                'postal_code' => $address->postalCode,
                'city' => $address->city,
            ],
        ];
    }
}
