<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure\Api;

use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\ReserveMovement;
use App\Module\Finance\Domain\ReserveMovementRepository;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\ApiValue;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;

/**
 * Die Bewegungen der Erhaltungsruecklage.
 *
 * Bewegungen und kein Stand: der Stand ist ihre Summe, und wer ihn als Zahl
 * herausgibt, gibt einen Stichtag mit heraus, den niemand gefragt hat.
 *
 * Stornierte Bewegungen stehen mit ihrem Vermerk da und fallen nicht weg —
 * eine Ruecklage, aus der still etwas verschwindet, ist nicht nachvollziehbar.
 */
final readonly class ReserveMovementsResource implements PublishesResource
{
    public function __construct(private ReserveMovementRepository $movements)
    {
    }

    public function name(): string
    {
        return 'reserve-movements';
    }

    public function permission(): string
    {
        return FinancePermissions::VIEW;
    }

    public function identifies(object $entity): ?string
    {
        return $entity instanceof ReserveMovement ? $entity->id() : null;
    }

    public function page(ApiQuery $query): ApiPage
    {
        $page = Page::of($query->page, $this->movements->countAll());

        return new ApiPage(
            array_map(self::describe(...), $this->movements->pageOf($page)),
            $page->number,
            $page->pages,
            $page->total,
        );
    }

    public function one(string $id): ?array
    {
        $found = $this->movements->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(ReserveMovement $movement): array
    {
        return [
            'id' => $movement->id(),
            'property_id' => $movement->propertyId(),
            'unit_id' => $movement->unitId(),
            'kind' => $movement->kind()->value,
            'occurred_on' => ApiValue::day($movement->occurredOn()),
            'amount' => ApiValue::money($movement->amount()),
            'effect' => ApiValue::money($movement->effect()),
            'reversed' => $movement->isReversed(),
            'reversed_at' => ApiValue::moment($movement->reversedAt()),
        ];
    }
}
