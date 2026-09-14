<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\EInvoice;

/**
 * Verkaeufer oder Kaeufer einer E-Rechnung.
 *
 * Die Anschrift in Teilen und im Inland: ImmoBase verwaltet deutsche
 * Immobilien, und ein Land daneben waere eine Angabe, die niemand pflegt.
 */
final readonly class EInvoiceParty
{
    public function __construct(
        public string $name,
        public string $street,
        public string $postalCode,
        public string $city,
        /** Die Adresse fuer E-Rechnungen und Antworten darauf — BT-34 und BT-49. */
        public string $eAddress,
        /** Steuernummer oder USt-IdNr. — nur beim Verkaeufer. */
        public string $taxNumber = '',
    ) {
    }

    /**
     * Welche Nummer es ist: `VA` fuer eine USt-IdNr. (BT-31), `FC` fuer eine Steuernummer (BT-32).
     *
     * Die Stammdaten fuehren beides in einem Feld. Eine deutsche USt-IdNr. hat
     * eine feste Gestalt — `DE` und neun Ziffern —, alles andere ist eine
     * Steuernummer.
     */
    public function taxScheme(): string
    {
        return 1 === preg_match('/^DE\d{9}$/D', $this->normalisedTaxNumber()) ? 'VA' : 'FC';
    }

    public function normalisedTaxNumber(): string
    {
        $compact = strtoupper(str_replace(' ', '', $this->taxNumber));

        return 1 === preg_match('/^DE\d{9}$/D', $compact) ? $compact : trim($this->taxNumber);
    }
}
