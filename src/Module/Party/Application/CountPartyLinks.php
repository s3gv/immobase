<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Contract\PartyLinkSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sammelt, was an einem Stammdatensatz haengt.
 *
 * Fragt jedes Modul, das sich als Quelle angemeldet hat. Gibt es keine, ist
 * die Antwort leer — und dann darf geloescht werden.
 */
final readonly class CountPartyLinks
{
    /**
     * @param iterable<PartyLinkSource> $sources
     */
    public function __construct(
        #[AutowireIterator('party.link_source')]
        private iterable $sources,
    ) {
    }

    /**
     * @param list<string> $partyIds
     *
     * @return array<string, list<string>>
     */
    public function forParties(array $partyIds): array
    {
        $links = [];

        foreach ($this->sources as $source) {
            foreach ($source->linksTo($partyIds) as $partyId => $found) {
                $links[$partyId] = array_values(array_unique([...$links[$partyId] ?? [], ...$found]));
            }
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    public function forParty(string $partyId): array
    {
        return $this->forParties([$partyId])[$partyId] ?? [];
    }

    public function anyFor(string $partyId): bool
    {
        return [] !== $this->forParty($partyId);
    }
}
