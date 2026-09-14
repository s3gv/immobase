<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure\Api;

use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanEvent;
use App\Module\Finance\Domain\LoanFilter;
use App\Module\Finance\Domain\LoanRepository;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\ApiValue;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;

/**
 * Darlehen der Gemeinschaft — Betrag, Satz, Laufzeit, Restschuld.
 *
 * **Keine Restschuld als Zahl, sondern die Vorgaenge.** Sie folgt aus
 * Sondertilgungen und Zinsaenderungen, und eine gerundete Momentaufnahme
 * waere genau die Art Bezugsgroesse, die nur so aussieht, als stimme sie. Wer
 * eine Entwicklung zeichnen will, rechnet sie aus dem, was passiert ist.
 */
final readonly class LoansResource implements PublishesResource
{
    public function __construct(private LoanRepository $loans)
    {
    }

    public function name(): string
    {
        return 'loans';
    }

    public function permission(): string
    {
        return FinancePermissions::VIEW;
    }

    public function identifies(object $entity): ?string
    {
        return $entity instanceof Loan ? $entity->id() : null;
    }

    public function page(ApiQuery $query): ApiPage
    {
        $filter = LoanFilter::none();
        $page = Page::of($query->page, $this->loans->countMatching($filter));
        $found = $this->loans->matching($filter, $page, Sort::by('number'));

        return new ApiPage(array_map(self::describe(...), $found), $page->number, $page->pages, $page->total);
    }

    public function one(string $id): ?array
    {
        $found = $this->loans->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Loan $loan): array
    {
        $terms = $loan->terms();

        return [
            'id' => $loan->id(),
            'number' => $loan->number(),
            'property_id' => $loan->propertyId(),
            'label' => $loan->label(),
            'lender' => $loan->lender(),
            'amount' => ApiValue::money($terms->amount()),
            'rate_bps' => $terms->rateBps(),
            'starts_on' => ApiValue::day($terms->startsOn()),
            'months' => $terms->months(),
            'payment' => null === $terms->payment() ? null : ApiValue::money($terms->payment()),
            'events' => array_map(self::describeEvent(...), $loan->events()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeEvent(LoanEvent $event): array
    {
        return [
            'id' => $event->id(),
            'kind' => $event->kind()->value,
            'occurred_on' => ApiValue::day($event->occurredOn()),
            'amount' => null === $event->amount() ? null : ApiValue::money($event->amount()),
            'rate_bps' => $event->rateBps(),
        ];
    }
}
