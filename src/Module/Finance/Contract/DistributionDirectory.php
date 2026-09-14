<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Die festen Anteile eines Verteilerschluessels.
 *
 * Nur die festen: berechnete Schluessel — Flaeche, Miteigentumsanteile,
 * Personen, nach Einheiten — stehen nicht in den Finanzen, sondern an der
 * Einheit und am Mietverhaeltnis. Wer verteilt, holt sie dort und nicht hier.
 * Diese Flaeche haette sie sonst zweimal.
 */
interface DistributionDirectory
{
    /**
     * @return array<string, string> Kennung der Einheit auf ihren Anteil
     */
    public function sharesOf(string $keyId): array;
}
