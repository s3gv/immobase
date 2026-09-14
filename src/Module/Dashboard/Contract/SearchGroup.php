<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Contract;

use App\Shared\Search\SearchHit;

/**
 * Die Treffer einer Art, zusammen mit dem Weg in ihre Liste.
 *
 * Ob es mehr gibt, wird nicht gezaehlt, sondern gesehen: wer die erbetene
 * Zahl voll ausschoepft, hat vermutlich mehr. Eine zaehlende Abfrage je Art
 * kostete bei jedem Tastendruck so viel wie die Suche selbst — fuer eine
 * Zahl, die niemand liest.
 */
final readonly class SearchGroup
{
    /**
     * @param list<SearchHit> $hits
     */
    public function __construct(
        public string $kindKey,
        public string $listUrl,
        public array $hits,
        public bool $mayHaveMore,
    ) {
    }
}
