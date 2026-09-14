<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Ein Darlehen, so wie ein Beschluss es vorsieht.
 *
 * Was die Versammlung beschliesst, ist der **Finanzierungsweg** und nicht der
 * Vertrag: Summe, Zins und Rate oder Laufzeit stehen im Beschluss, die Bank
 * und der Tag der ersten Rate noch nicht. Beides traegt nach, wer den Vertrag
 * unterschreibt.
 *
 * Die Referenz ist die des Beschlusses. An ihr erkennt die Finanzseite, dass
 * ein zweiter Beschluss derselben Massnahme kein zweites Darlehen ist.
 */
final readonly class DecidedLoan
{
    public function __construct(
        public string $propertyId,
        public string $reference,
        public string $label,
        public Money $amount,
        public int $rateBps,
        public DateTimeImmutable $startsOn,
        /** Entweder die Rate oder die Laufzeit — was der Beschluss nennt. */
        public ?Money $payment,
        public ?int $months,
    ) {
    }
}
