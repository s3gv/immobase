<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Eine offene Forderung, wie sie im Bericht steht.
 *
 * Dieselbe Gestalt, ob sie eben erst gerechnet oder laengst eingefroren
 * wurde: die Vorschau eines Entwurfs und das zugestellte Schreiben zeigen
 * denselben Satz, und zwei Gestalten dafuer waeren zwei Gelegenheiten, dass
 * sie sich unterscheiden.
 */
final readonly class ReportedClaim
{
    public function __construct(
        public int $unitNumber,
        public Money $amount,
        public int $since,
    ) {
    }
}
