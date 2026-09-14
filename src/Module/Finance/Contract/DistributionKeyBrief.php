<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Ein Verteilerschluessel, so viel wie ein fremdes Modul davon sehen darf.
 *
 * `kind` ist die Sorte als Zeichenkette — `area`, `mea`, `persons`, `units`,
 * `metered`, `fixed`. Sie steht dabei, weil an ihr haengt, woher die Anteile
 * kommen und ob es sie fuer ein kuenftiges Jahr ueberhaupt schon gibt.
 */
final readonly class DistributionKeyBrief
{
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
    ) {
    }
}
