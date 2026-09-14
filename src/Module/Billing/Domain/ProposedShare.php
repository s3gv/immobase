<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Was auf eine Einheit entfaellt.
 *
 * Vier Zahlen, vier Fragen: was traegt sie an der Massnahme, was zahlt sie
 * jetzt als Sonderumlage, was spart sie dafuer jaehrlich mehr an, und was
 * entfaellt monatlich auf sie an der Darlehensrate.
 *
 * Dieselbe Gestalt, ob eben gerechnet oder laengst eingefroren — die Vorschau
 * eines Entwurfs und das zugestellte Schreiben zeigen denselben Satz.
 */
final readonly class ProposedShare
{
    public function __construct(
        public string $unitId,
        public int $unitNumber,
        public string $unitLabel,
        public string $recipientLabel,
        public string $recipientAddress,
        public Money $share,
        public Money $levy,
        public Money $saving,
        public Money $loanPayment,
        /**
         * Die Sonderumlage in Raten — eine Zahl je Faelligkeit.
         *
         * @var list<Money>
         */
        public array $levyParts = [],
    ) {
    }
}
