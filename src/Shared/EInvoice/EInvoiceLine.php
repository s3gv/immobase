<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\EInvoice;

use App\Shared\Money\Money;

/**
 * Eine Position — Menge eins, der Betrag ist der Nettopreis.
 *
 * Miete und Nebenkosten werden nicht gezaehlt, sondern berechnet: eine
 * Kaltmiete im Monat, ein Anteil an der Grundsteuer. Eine andere Menge als
 * eins waere eine Angabe, die es auf dem Blatt nicht gibt.
 */
final readonly class EInvoiceLine
{
    /** Ein Monat (UN/ECE Rec 20). */
    public const string MONTH = 'MON';

    /** Ein Stueck, eine Einheit (UN/ECE Rec 20). */
    public const string ONE = 'C62';

    public function __construct(
        public string $name,
        public Money $net,
        public string $unitCode = self::ONE,
    ) {
    }
}
