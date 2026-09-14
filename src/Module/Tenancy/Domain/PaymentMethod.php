<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

/**
 * Wie die Miete kommt.
 *
 * Zwei Wege, weil es in der Praxis zwei gibt: der Mieter ueberweist, oder die
 * Verwaltung zieht ein. Das Mandat selbst — Referenz, Datum, Bankverbindung —
 * gehoert nicht hierher, sondern zum Zahlungsverkehr.
 */
enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case DirectDebit = 'direct_debit';

    public function labelKey(): string
    {
        return 'tenancy.payment.method.'.$this->value;
    }
}
