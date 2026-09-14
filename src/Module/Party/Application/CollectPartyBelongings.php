<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Contract\PartyBelongings;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sammelt, was mit einem Stammdatensatz verschwindet — und raeumt es weg.
 *
 * Fragt jedes Modul, das sich angemeldet hat. Gibt es keines, verschwindet
 * nichts ausser der Partei selbst.
 */
final readonly class CollectPartyBelongings
{
    /**
     * @param iterable<PartyBelongings> $sources
     */
    public function __construct(
        #[AutowireIterator('party.belongings')]
        private iterable $sources,
    ) {
    }

    /**
     * @return list<string>
     */
    public function forParty(string $partyId): array
    {
        $announced = [];

        foreach ($this->sources as $source) {
            foreach ($source->announceFor([$partyId])[$partyId] ?? [] as $line) {
                $announced[] = $line;
            }
        }

        return $announced;
    }

    public function discardFor(string $partyId): void
    {
        foreach ($this->sources as $source) {
            $source->discardFor([$partyId]);
        }
    }
}
