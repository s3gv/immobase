<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure\Api;

use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;

/**
 * Die Kostenarten — der Schluessel jeder Kostenauswertung.
 *
 * Ein Katalog und keine Bewegungsdaten: er hat Dutzende Eintraege, keine
 * Zehntausende. Geblaettert wird trotzdem, damit jede Ressource sich gleich
 * verhaelt — ein Aufrufer soll nicht wissen muessen, welche Liste kurz ist.
 */
final readonly class CostKindsResource implements PublishesResource
{
    public function __construct(private CostKindRepository $kinds)
    {
    }

    public function name(): string
    {
        return 'cost-kinds';
    }

    public function permission(): string
    {
        return FinancePermissions::VIEW;
    }

    public function identifies(object $entity): ?string
    {
        return $entity instanceof CostKind ? $entity->id() : null;
    }

    public function page(ApiQuery $query): ApiPage
    {
        $all = $this->kinds->all();
        $page = Page::of($query->page, \count($all));

        return new ApiPage(
            array_map(self::describe(...), \array_slice($all, $page->offset(), $page->limit())),
            $page->number,
            $page->pages,
            $page->total,
        );
    }

    public function one(string $id): ?array
    {
        $found = $this->kinds->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(CostKind $kind): array
    {
        return [
            'id' => $kind->id(),
            'name' => $kind->name(),
            'apportionable' => $kind->isApportionable(),
            'system' => $kind->isSystem(),
        ];
    }
}
