<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Eine ausgestellte Rechnung wird nicht mehr geaendert.
 *
 * Sie liegt beim Mieter und traegt eine Nummer, mit der er Vorsteuer zieht.
 * Was daran falsch ist, wird berichtigt — in einer neuen Fassung mit eigener
 * Nummer. Was sich geaendert hat, bekommt eine Folgefassung.
 */
final class RentInvoiceIsIssued extends RuntimeException
{
    public static function already(): self
    {
        return new self('billing.error.invoice_issued');
    }
}
