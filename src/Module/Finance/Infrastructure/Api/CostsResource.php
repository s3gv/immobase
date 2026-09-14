<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure\Api;

use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemFilter;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\FinancePermissions;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\ApiValue;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;

/**
 * Kostenpositionen samt ihren Jahreswerten.
 *
 * **Die Jahre kommen mit.** Eine Kostenposition ohne ihre Werte waere fuer
 * eine Auswertung nichts wert, und sie einzeln nachzufragen hiesse, fuer
 * einen Mehrjahresvergleich tausend Anfragen zu stellen.
 */
final readonly class CostsResource implements PublishesResource
{
    public function __construct(private CostItemRepository $items)
    {
    }

    public function name(): string
    {
        return 'costs';
    }

    public function permission(): string
    {
        return FinancePermissions::VIEW;
    }

    /** Auch ein Jahreswert: er ist der Betrag, um den es geht. */
    public function identifies(object $entity): ?string
    {
        return match (true) {
            $entity instanceof CostItem => $entity->id(),
            $entity instanceof CostItemYear => $entity->item()->id(),
            default => null,
        };
    }

    public function page(ApiQuery $query): ApiPage
    {
        $filter = CostItemFilter::none();
        $page = Page::of($query->page, $this->items->countMatching($filter));
        $found = $this->items->matching($filter, $page, Sort::by('number'));

        return new ApiPage(array_map(self::describe(...), $found), $page->number, $page->pages, $page->total);
    }

    public function one(string $id): ?array
    {
        $found = $this->items->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(CostItem $item): array
    {
        return [
            'id' => $item->id(),
            'number' => $item->number(),
            'property_id' => $item->propertyId(),
            'cost_kind_id' => $item->kind()->id(),
            'cost_kind' => $item->kind()->name(),
            'apportionable' => $item->isApportionable(),
            'distribution_key' => $item->key()->name(),
            'years' => array_map(self::describeYear(...), $item->years()->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeYear(CostItemYear $year): array
    {
        return [
            'fiscal_year' => $year->fiscalYear(),
            'amount' => ApiValue::money($year->amount()),
        ];
    }
}
