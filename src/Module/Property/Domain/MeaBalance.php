<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Was verteilt ist gegen das, was verteilt sein sollte.
 *
 * Wird angezeigt und blockiert nie. Einheiten werden ueber Wochen erfasst,
 * und eine Sperre „erst wenn die Summe stimmt" hielte genau die Erfassung an,
 * die zur Summe fuehrt. Sichtbar sein muss es trotzdem: eine
 * Hausgeldabrechnung auf 940 von 1000 Anteilen ist falsch, und zwar leise.
 */
final readonly class MeaBalance
{
    private function __construct(
        public Mea $distributed,
        public Mea $expected,
    ) {
    }

    public static function of(Mea $distributed, Mea $expected): self
    {
        return new self($distributed, $expected);
    }

    public function isComplete(): bool
    {
        return $this->distributed->equals($this->expected);
    }

    /** Nichts verteilt heisst: noch nicht angefangen, nicht falsch. */
    public function isUntouched(): bool
    {
        return $this->distributed->isZero();
    }

    public function isShort(): bool
    {
        return $this->distributed->isLessThan($this->expected);
    }

    /** Was noch fehlt — null, wenn nichts fehlt. */
    public function missing(): Mea
    {
        return $this->expected->minus($this->distributed);
    }

    /** Was zu viel ist — null, wenn nichts zu viel ist. */
    public function excess(): Mea
    {
        return $this->distributed->minus($this->expected);
    }
}
