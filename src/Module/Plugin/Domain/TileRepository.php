<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use DateTimeImmutable;

interface TileRepository
{
    /**
     * Setzt die Kacheln eines Plugins neu — alle auf einmal.
     *
     * Ersetzt und nicht ergaenzt: eine Kachel, die das Plugin nicht mehr
     * liefert, soll verschwinden und nicht als letzter bekannter Stand
     * stehen bleiben.
     *
     * @param list<Tile> $tiles
     */
    public function replace(string $plugin, array $tiles): void;

    /**
     * Was frisch genug ist, um gezeigt zu werden.
     *
     * @return list<Tile>
     */
    public function fresh(DateTimeImmutable $since): array;

    public function forgetPlugin(string $plugin): void;
}
