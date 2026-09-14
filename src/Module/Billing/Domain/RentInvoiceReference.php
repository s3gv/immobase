<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Die Rechnungsnummer: `DM-30001-2-1`.
 *
 * Mietverhaeltnis, Folgefassung, Berichtigung. § 14 Abs. 4 Nr. 4 UStG
 * verlangt eine **fortlaufende Nummer, die einmalig vergeben wird**, und
 * erlaubt dafuer ausdruecklich mehrere Zahlenreihen — eine sprechende Nummer
 * ist damit zulaessig und reiht sich neben `HG-20001/2-2026-3-1` ein.
 *
 * Auch die Berichtigung bekommt so ihre eigene Nummer. Das braucht sie: sie
 * tritt neben die berichtigte Rechnung und nicht an ihre Stelle, und zwei
 * Schreiben mit derselben Nummer waeren fuer den Vorsteuerabzug des Mieters
 * ein Problem.
 *
 * Zusammengesetzt und nicht gespeichert: die Teile stehen ohnehin einzeln
 * da, und zwei Wahrheiten laufen auseinander.
 */
final class RentInvoiceReference
{
    private function __construct()
    {
    }

    public static function of(RentInvoice $invoice): string
    {
        return \sprintf(
            'DM-%d-%d-%d',
            $invoice->tenancyNumber(),
            $invoice->edition()->number(),
            $invoice->edition()->iteration(),
        );
    }
}
