<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Eine Kostenart, so viel wie ein fremdes Modul davon sehen darf.
 *
 * Fuer die Auswahl an einer Planposition: welche Art, und ob sie umlagefaehig
 * ist. Die Umlagefaehigkeit wird dabei angezeigt und nicht gepflegt — sie
 * gehoert der Kostenart und nicht dem Plan.
 */
final readonly class CostKindBrief
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $apportionable,
    ) {
    }
}
