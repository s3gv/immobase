<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimFilter;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Forderungen des Mahnwesens in der zentralen Suche.
 *
 * Gesucht wird ueber den Betreff — das ist, was an einer Forderung in Worten
 * steht. Der Name des Schuldners kommt aus den Stammdaten und steht in der
 * Trefferzeile: wer eine Forderung sucht, sucht sie meist unter dem Namen,
 * und der Name soll dabeistehen, auch wenn er nicht der Suchbegriff war.
 */
#[AsTaggedItem(priority: 40)]
final readonly class ClaimSearch implements SearchesRecords
{
    public function __construct(
        private ClaimRepository $claims,
        private PartyDirectory $parties,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.claim';
    }

    public function listUrl(SearchTerm $term): string
    {
        return $this->urls->generate('app_dunning', ['q' => $term->raw]);
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(DunningPermissions::VIEW)) {
            return [];
        }

        $found = $this->claims->matching(ClaimFilter::of(null, null, $term->text), Page::of(1, Page::PER_PAGE));
        $shown = \array_slice($found, 0, $limit);

        // Die Namen fuer alle Treffer auf einmal: einer je Treffer waeren
        // zwanzig Abfragen auf einer Seite — und die Vorschlagsliste holt sie
        // bei jedem Tastendruck.
        $debtors = $this->parties->byIds(array_map(
            static fn (Claim $claim): string => $claim->debtor()->partyId(),
            $shown,
        ));

        return array_map(
            fn (Claim $claim): SearchHit => $this->hit($claim, $debtors),
            $shown,
        );
    }

    /**
     * @param array<string, PartyBrief> $debtors
     */
    private function hit(Claim $claim, array $debtors): SearchHit
    {
        return new SearchHit(
            title: $claim->subject(),
            subtitle: $debtors[$claim->debtor()->partyId()]->displayName ?? '',
            reference: '',
            url: $this->urls->generate('app_dunning_show', ['id' => $claim->id()]),
        );
    }
}
