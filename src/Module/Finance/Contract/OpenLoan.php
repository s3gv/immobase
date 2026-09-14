<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;

/**
 * Ein Darlehen, so viel wie der Vermoegensbericht davon braucht.
 *
 * Eine Bezeichnung und eine Restschuld. Kein Zinssatz, keine Laufzeit: der
 * Bericht stellt das Vermoegen auf und nicht die Vertraege — wer die
 * Konditionen sucht, findet sie in den Finanzen.
 */
final readonly class OpenLoan
{
    public function __construct(
        public string $label,
        public Money $outstanding,
    ) {
    }
}
