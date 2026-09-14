<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;

/**
 * Was eine beschlossene Massnahme bisher gekostet hat — ein Jahreswert davon.
 *
 * Die Gegenrichtung zur Sonderumlage: dort steht, was hereinkam, hier, was
 * hinausging. Beide tragen die Nummer desselben Beschlusses.
 */
final readonly class MeasureCost
{
    public function __construct(
        public int $itemNumber,
        public string $kindLabel,
        public int $fiscalYear,
        public Money $amount,
    ) {
    }
}
