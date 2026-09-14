<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Contract;

use App\Shared\Money\Money;

/**
 * Was das Mahnwesen gerade drueckt.
 *
 * **Gezaehlt werden Schreiben, nicht Forderungen.** „3" heisst: drei Briefe
 * zu schreiben. Zwei offene Forderungen desselben Schuldners beim selben
 * Glaeubiger sind eine Meldung — eine Zahl, die Forderungen zaehlte,
 * verspraeche mehr Arbeit, als es ist.
 */
final readonly class DunningPressure
{
    public function __construct(
        /** Faellige Schreiben — ueberfaellig ohne Vorgang, oder Frist abgelaufen. */
        public int $letters,
        /** Vorgaenge, bei denen nach der letzten Mahnung nur noch das Gericht bleibt. */
        public int $forTheCourt,
        public Money $open,
    ) {
    }

    public static function nothing(): self
    {
        return new self(0, 0, Money::zero());
    }

    public function isQuiet(): bool
    {
        return 0 === $this->letters && 0 === $this->forTheCourt;
    }
}
