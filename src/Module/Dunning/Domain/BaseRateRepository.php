<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

/**
 * Die Reihe der Basiszinssaetze.
 */
interface BaseRateRepository
{
    public function save(BaseRate $rate): void;

    public function remove(BaseRate $rate): void;

    public function byId(string $id): ?BaseRate;

    /** Die ganze Reihe — sie ist kurz, zwei Zeilen im Jahr. */
    public function all(): BaseRates;
}
