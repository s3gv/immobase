<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Eine faellige Vorauszahlung und was ankam.
 *
 * `received` ist bereits aufgeloest: bei „bezahlt" der Sollbetrag, sonst die
 * Teilzahlung oder null. Wer abrechnet, soll die drei Zustaende dahinter
 * nicht kennen muessen — er braucht eine Zahl.
 */
final readonly class PaymentRecord
{
    public function __construct(
        /** Die Kennung geht als Quelle in die Abrechnung ein. */
        public string $paymentId,
        public string $unitId,
        /** house_money | operating_costs | special_levy */
        public string $kind,
        /**
         * Schuldet der Eigentuemer sie — oder sein Mieter?
         *
         * Die Frage beantworten die Finanzen und nicht die Abrechnung: dort
         * steht, was fuer eine Zahlung es ist. Die Abrechnung hat nur zwei
         * Sorten Schreiben, und diese Angabe sagt, in welches sie gehoert.
         */
        public bool $owedByTheOwner,
        public DateTimeImmutable $dueOn,
        public Money $expected,
        public Money $received,
        /**
         * Die Nummer des Beschlusses bei einer Sonderumlage, sonst leer.
         *
         * Damit die Abrechnung fragen kann, ob den eingesammelten Betraegen
         * auch Rechnungen gegenueberstehen.
         */
        public string $reference = '',
    ) {
    }
}
