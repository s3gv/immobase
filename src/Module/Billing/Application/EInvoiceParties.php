<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\EInvoiceData;
use App\Shared\EInvoice\EInvoiceParty;

/**
 * Verkaeufer und Kaeufer aus dem, was an einem Beleg eingefroren ist.
 *
 * Der Verkaeufer bekommt als elektronische Adresse die der Verwaltung: sie
 * stellt die Rechnung aus, und Antworten darauf sollen dort ankommen, wo sie
 * bearbeitet werden (BT-34).
 */
final class EInvoiceParties
{
    private function __construct()
    {
    }

    public static function seller(string $name, EInvoiceData $data, string $taxNumber): EInvoiceParty
    {
        $address = $data->seller();

        return new EInvoiceParty($name, $address['street'], $address['postalCode'], $address['city'], $data->contact()['email'], $taxNumber);
    }

    public static function buyer(string $name, EInvoiceData $data): EInvoiceParty
    {
        $address = $data->buyer();

        return new EInvoiceParty($name, $address['street'], $address['postalCode'], $address['city'], $data->buyerEAddress());
    }
}
