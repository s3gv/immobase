<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use App\Shared\Money\Money;

/**
 * Was der Mieter einer Einheit an Vorauszahlung leistet.
 *
 * Steht im Mietvertrag und damit in der Mietstaffel — hier wird sie nur
 * gelesen. Eine zweite Fassung in den Finanzen zu fuehren hiesse, dieselbe
 * Zahl zweimal zu haben und synchron zu halten.
 *
 * `url` fuehrt zum Mietverhaeltnis: geaendert wird sie dort, wo sie steht.
 */
final readonly class UnitAdvance
{
    public function __construct(
        public string $unitId,
        public int $tenancyNumber,
        public Money $operatingCosts,
        public Money $heating,
        public string $url,
        /** Mit Umsatzsteuer vermietet: der Satz in Basispunkten, sonst null. */
        public int $vatRateBps = 0,
    ) {
    }

    /**
     * Was der Mieter im Monat an Nebenkosten zahlt.
     *
     * Mit Umsatzsteuer vermietet brutto — so, wie die Dauermietrechnung sie
     * ausweist und die Zahlungen sie erwarten. Zwei Zahlen fuer dieselbe
     * Ueberweisung liessen jeden rechnen, der eine davon sieht.
     */
    public function total(): Money
    {
        $net = $this->operatingCosts->plus($this->heating);

        return $net->plus($net->basisPoints($this->vatRateBps));
    }
}
