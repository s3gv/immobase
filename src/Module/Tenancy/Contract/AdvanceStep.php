<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Was in einem Zeitabschnitt monatlich vorausgezahlt wurde.
 *
 * Kaltmiete und Stellplatz stehen nicht dabei: eine Nebenkostenabrechnung
 * rechnet gegen die Vorauszahlungen ab, und die Miete ist keine.
 */
final readonly class AdvanceStep
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public Money $operatingCosts,
        public Money $heating,
    ) {
    }
}
