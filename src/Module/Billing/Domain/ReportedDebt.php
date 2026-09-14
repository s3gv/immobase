<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Ein Darlehen, wie es im Vermoegensbericht steht.
 *
 * Dieselbe Gestalt, ob eben gerechnet oder laengst eingefroren — wie bei
 * {@see ReportedClaim}. Der Entwurf holt sie aus dem Tilgungsplan, das
 * zugestellte Schreiben aus dem, was bei der Herausgabe festgehalten wurde.
 */
final readonly class ReportedDebt
{
    public function __construct(
        public string $label,
        public Money $outstanding,
    ) {
    }
}
