<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Application;

use App\Module\Dashboard\Contract\SearchGroup;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchTerm;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Die eine Suche, die alle fragt.
 *
 * Sie kennt kein Fachmodul: sie fragt, wer sich als
 * {@see SearchesRecords} gemeldet hat, und reiht die Antworten auf. Was ein
 * guter Treffer ist, entscheidet die Quelle — eine Rangfolge ueber Module
 * hinweg waere geraten, und geratene Reihenfolgen sind die, ueber die sich
 * niemand beschwert und die trotzdem alle stoeren.
 *
 * Rechte prueft sie nicht. Das tut jede Quelle fuer sich; hier zu pruefen
 * hiesse, alle Rechte aller Module an einer Stelle zu kennen.
 */
final readonly class FindsEverything
{
    /** So viele je Art klappen unter dem Feld auf. */
    public const int SUGGESTED = 5;

    /** So viele je Art stehen auf der Trefferseite. */
    public const int LISTED = 20;

    /**
     * @param iterable<SearchesRecords> $sources
     */
    public function __construct(
        #[AutowireIterator('search.source')]
        private iterable $sources,
    ) {
    }

    /**
     * @return list<SearchGroup> nur Arten mit Treffern
     */
    public function matching(SearchTerm $term, int $perKind): array
    {
        $groups = [];

        foreach ($this->sources as $source) {
            $hits = $source->matching($term, $perKind);

            if ([] === $hits) {
                continue;
            }

            $groups[] = new SearchGroup(
                $source->kindKey(),
                $source->listUrl($term),
                $hits,
                \count($hits) >= $perKind,
            );
        }

        return $groups;
    }
}
