<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;

/**
 * Was eine Einheit der Gemeinschaft am Stichtag noch schuldete.
 *
 * Soll und Ist stehen beide da, nicht nur die Differenz: „offen 240,00" sagt
 * nichts darueber, ob zwei Monate fehlen oder ob jemand seit Jahren zu wenig
 * ueberweist. Das aelteste Jahr mit einem Rueckstand sagt es.
 */
final readonly class OpenClaim
{
    public function __construct(
        public string $unitId,
        public Money $expected,
        public Money $received,
        public Money $open,
        /** Das aelteste Wirtschaftsjahr, in dem etwas offen blieb. */
        public int $since,
    ) {
    }
}
