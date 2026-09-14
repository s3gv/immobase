<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Party\Domain\PartyRepository;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Stammdaten in der zentralen Suche.
 *
 * Gefunden wird ein Kontakt ueber alles, was an ihm steht — Name, Nummer,
 * Anschrift, Mailadresse, Telefonnummer, Steuernummer. Wer jemanden sucht,
 * hat selten dessen Nummer im Kopf; er hat die Strasse im Kopf, an die er
 * letzte Woche geschrieben hat.
 *
 * Ohne Leserecht liefert die Quelle nichts. Sie prueft das selbst: die Suche
 * soll nicht wissen muessen, welches Recht zu welchem Modul gehoert.
 */
#[AsTaggedItem(priority: 80)]
final readonly class PartySearch implements SearchesRecords
{
    public function __construct(
        private PartyRepository $parties,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.party';
    }

    public function listUrl(SearchTerm $term): string
    {
        return $this->urls->generate('app_party', ['q' => $term->raw]);
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(PartyPermissions::VIEW)) {
            return [];
        }

        return array_map(
            fn (Party $party): SearchHit => $this->hit($party),
            $this->parties->anywhere($term, $limit),
        );
    }

    private function hit(Party $party): SearchHit
    {
        return new SearchHit(
            title: $party->displayName(),
            subtitle: $party->addresses()->primary()->oneLine(),
            reference: (string) $party->reference(),
            url: $this->urls->generate('app_party_show', ['reference' => $party->reference()]),
        );
    }
}
