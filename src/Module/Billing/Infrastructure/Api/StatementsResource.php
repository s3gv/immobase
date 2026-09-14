<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure\Api;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementFilter;
use App\Module\Billing\Domain\StatementRepository;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\ApiValue;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;

/**
 * Abrechnungen — welche es gibt und ob sie festgeschrieben sind.
 *
 * **Ohne Betraege.** Eine Abrechnung ist kein Wert, sondern ein Vorgang mit
 * Einzelposten je Einheit; sie hier als eine Zahl auszugeben hiesse, eine
 * Bezugsgroesse zu erfinden. Was ein Plugin ueber die Zahlen wissen will,
 * steht in den Kosten.
 *
 * Entwuerfe stehen mit dabei und sind als solche erkennbar: wer zaehlt, wie
 * viele Abrechnungen offen sind, braucht gerade sie.
 */
final readonly class StatementsResource implements PublishesResource
{
    public function __construct(private StatementRepository $statements)
    {
    }

    public function name(): string
    {
        return 'statements';
    }

    public function permission(): string
    {
        return BillingPermissions::VIEW;
    }

    public function identifies(object $entity): ?string
    {
        return $entity instanceof Statement ? $entity->id() : null;
    }

    public function page(ApiQuery $query): ApiPage
    {
        $filter = StatementFilter::none();
        $page = Page::of($query->page, $this->statements->countMatching($filter));

        return new ApiPage(
            array_map(self::describe(...), $this->statements->matching($filter, $page)),
            $page->number,
            $page->pages,
            $page->total,
        );
    }

    public function one(string $id): ?array
    {
        $found = $this->statements->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Statement $statement): array
    {
        return [
            'id' => $statement->id(),
            'number' => $statement->number(),
            'iteration' => $statement->iteration(),
            'property_id' => $statement->propertyId(),
            'fiscal_year' => $statement->fiscalYear(),
            'label' => $statement->label(),
            'status' => $statement->release()->status()->value,
            'released_on' => ApiValue::day($statement->release()->day()),
            'corrects_id' => $statement->correctsId(),
        ];
    }
}
