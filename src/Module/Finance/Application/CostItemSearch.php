<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemFilter;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Kostenpositionen in der zentralen Suche.
 *
 * Ueber denselben Filter wie die Liste des Moduls: Kostenart, Bezeichnung des
 * Verteilerschluessels, Nummer. Ein zweiter Weg dorthin liefe frueher oder
 * spaeter anders als der erste, und dann faende die Suche etwas, das die
 * Liste nicht zeigt.
 *
 * Mit den beendeten: wer eine abgeschlossene Position sucht, sucht sie, weil
 * er sie meint.
 */
#[AsTaggedItem(priority: 50)]
final readonly class CostItemSearch implements SearchesRecords
{
    public function __construct(
        private CostItemRepository $items,
        private PropertyDirectory $properties,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.cost_item';
    }

    public function listUrl(SearchTerm $term): string
    {
        return $this->urls->generate('app_finance_item', ['q' => $term->raw]);
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(FinancePermissions::VIEW)) {
            return [];
        }

        $filter = CostItemFilter::of(null, null, null, $term->text, withPast: true);
        $found = $this->items->matching($filter, Page::of(1, Page::PER_PAGE), Sort::by('nummer'));
        $shown = \array_slice($found, 0, $limit);

        // Die Objekte fuer alle Treffer auf einmal: eines je Treffer waeren
        // zwanzig Abfragen — genau das Muster, vor dem `byIds()` warnt.
        $properties = $this->properties->byIds(array_map(
            static fn (CostItem $item): string => $item->propertyId(),
            $shown,
        ));

        return array_map(
            fn (CostItem $item): SearchHit => $this->hit($item, $properties),
            $shown,
        );
    }

    /**
     * @param array<string, PropertyBrief> $properties
     */
    private function hit(CostItem $item, array $properties): SearchHit
    {
        return new SearchHit(
            title: $item->kind()->name(),
            subtitle: $properties[$item->propertyId()]->name ?? '',
            reference: (string) $item->number(),
            url: $this->urls->generate('app_finance_item_show', ['number' => $item->number()]),
        );
    }
}
