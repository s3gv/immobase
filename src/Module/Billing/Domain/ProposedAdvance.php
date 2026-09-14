<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

/** Eine Vorauszahlung auf einem Vorschlag. */
final readonly class ProposedAdvance
{
    public function __construct(
        public string $paymentId,
        public DateTimeImmutable $dueOn,
        public Money $expected,
        public Money $received,
        /** Hausgeld, Nebenkosten oder Sonderumlage — fuer die Zeile auf dem Blatt. */
        public string $kind,
    ) {
    }

    /**
     * Der Schluessel des Namens.
     *
     * Heisst wie {@see StatementAdvance::kindKey()}, damit die Vorschau
     * dieselbe Vorlage benutzen kann wie das herausgegebene Schreiben — ein
     * Vorschlag und ein eingefrorenes Blatt sehen sonst verschieden aus, und
     * genau das soll die Vorschau ausschliessen.
     */
    public function kindKey(): string
    {
        return 'finance.payment.kind.'.$this->kind;
    }
}
