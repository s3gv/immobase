<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Was fehlt, haelt die Ausstellung auf.
 *
 * Eine Rechnung, der eine Pflichtangabe fehlt, ist keine: der Mieter zieht
 * daraus keine Vorsteuer, und er merkt es erst, wenn sein Finanzamt es ihm
 * sagt. Die Luecken stehen deshalb benannt auf dem letzten Schritt.
 */
final class RentInvoiceIsIncomplete extends RuntimeException
{
    public static function somethingIsMissing(): self
    {
        return new self('billing.error.invoice_incomplete');
    }
}
