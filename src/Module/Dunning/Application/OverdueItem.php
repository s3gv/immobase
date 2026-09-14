<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\CreditorIdentity;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Eine ueberfaellige Zahlung, zu der es noch keinen Vorgang gibt.
 *
 * Abgeleitet und nicht gespeichert: solange niemand gemahnt hat, steht alles
 * schon in den Finanzen. Erst der Klick auf „Mahnen" macht daraus eine
 * Forderung mit Eigenleben.
 */
final readonly class OverdueItem
{
    public function __construct(
        public string $paymentId,
        public string $unitId,
        public string $propertyId,
        public string $unitLabel,
        public string $subject,
        public CreditorIdentity $creditor,
        public string $debtorPartyId,
        public string $debtorName,
        public bool $debtorIsACompany,
        public DateTimeImmutable $dueOn,
        public Money $open,
        public int $daysOverdue,
    ) {
    }
}
