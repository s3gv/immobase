<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Eine beschlossene Massnahme, so flach wie die Finanzen sie brauchen.
 *
 * Die Nummer ist die des Beschlusses und nicht die eines Schreibens: ohne
 * Einheit, ohne Fassung. Dieselbe steht an der Sonderumlage, die die
 * Massnahme bezahlt — und ab jetzt an der Rechnung, die sie kostet.
 */
final readonly class DecidedMeasure
{
    public function __construct(
        /** Zum Beispiel `BU-20001-2027-3`. */
        public string $reference,
        public string $label,
        public int $firstYear,
    ) {
    }
}
