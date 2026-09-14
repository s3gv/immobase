<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

/**
 * In welcher Form die Kaution geleistet wurde.
 *
 * Die Form entscheidet, wie sie zurueckgegeben wird — eine Buergschaft wird
 * herausgegeben, eine Barkaution ueberwiesen. Fuer die Verwaltung ist das der
 * Unterschied zwischen einem Brief und einer Zahlung.
 */
enum DepositKind: string
{
    case Cash = 'cash';
    case Guarantee = 'guarantee';
    case Savings = 'savings';

    public function labelKey(): string
    {
        return 'tenancy.deposit.kind.'.$this->value;
    }
}
