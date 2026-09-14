<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Finance\Contract\DistributionDirectory;

/**
 * Die festen Anteile eines Schluessels, einmal geholt und gemerkt.
 *
 * Ein Lauf fragt denselben Schluessel fuer jede Einheit — ohne das Merken
 * waere das je Einheit eine Abfrage.
 */
final class DistributionShares
{
    /** @var array<string, array<string, string>> */
    private array $known = [];

    public function __construct(private readonly DistributionDirectory $keys)
    {
    }

    /** Null heisst: fuer diese Einheit ist kein Anteil hinterlegt. */
    public function of(string $keyId, string $unitId): ?string
    {
        $this->known[$keyId] ??= $this->keys->sharesOf($keyId);

        return $this->known[$keyId][$unitId] ?? null;
    }
}
