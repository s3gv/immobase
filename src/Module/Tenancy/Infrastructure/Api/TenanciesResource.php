<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Infrastructure\Api;

use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\ApiValue;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;

/**
 * Mietverhaeltnisse — Anfang, Ende und wer mietet.
 *
 * Aus Anfang und Ende je Einheit laesst sich Leerstand rechnen; das ist die
 * Frage, fuer die eine Auswertung sie braucht. Die Miete selbst steht nicht
 * darin: sie ist ein Verlauf mit Stufen, und ein einzelner Betrag waere die
 * Art von Zahl, die nur so aussieht, als stimme sie.
 */
final readonly class TenanciesResource implements PublishesResource
{
    public function __construct(private TenancyRepository $tenancies)
    {
    }

    public function name(): string
    {
        return 'tenancies';
    }

    public function permission(): string
    {
        return TenancyPermissions::VIEW;
    }

    /** Auch ein Mieter: er steht in der Auskunft ueber das Mietverhaeltnis. */
    public function identifies(object $entity): ?string
    {
        return match (true) {
            $entity instanceof Tenancy => $entity->id(),
            $entity instanceof Tenant => $entity->tenancy()->id(),
            default => null,
        };
    }

    public function page(ApiQuery $query): ApiPage
    {
        $filter = TenancyFilter::none();
        $page = Page::of($query->page, $this->tenancies->countMatching($filter));
        $found = $this->tenancies->matching($filter, $page, Sort::by('number'));

        return new ApiPage(array_map(self::describe(...), $found), $page->number, $page->pages, $page->total);
    }

    public function one(string $id): ?array
    {
        $found = $this->tenancies->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Tenancy $tenancy): array
    {
        $term = $tenancy->term();

        return [
            'id' => $tenancy->id(),
            'number' => $tenancy->number(),
            'unit_id' => $tenancy->unitId(),
            'status' => $tenancy->status()->value,
            'starts_on' => ApiValue::day($term->startsOn()),
            'ends_on' => ApiValue::day($term->endsOn()),
            'open_ended' => $term->isOpenEnded(),
            'tenants' => array_map(
                static fn (Tenant $tenant): string => $tenant->partyId(),
                $tenancy->tenants(),
            ),
        ];
    }
}
