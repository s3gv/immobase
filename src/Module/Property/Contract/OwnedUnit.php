<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

use DateTimeImmutable;

/**
 * Eine Einheit, die jemandem gehoert — und seit wann.
 *
 * Der Zeitraum steht mit dabei, weil Eigentum einen hat: wer im Juli gekauft
 * hat, war im Juni nicht Eigentuemer, und ein Portal, das ihm die Einheit
 * ohne dieses „seit" zeigt, behauptet etwas Falsches ueber die Vergangenheit.
 *
 * Null bei `from` heisst: gehoerte ihm schon, als die Verwaltung das Objekt
 * uebernahm. Null bei `to`: gehoert ihm noch.
 */
final readonly class OwnedUnit
{
    public function __construct(
        public UnitBrief $unit,
        /** Der Zaehler des Miteigentumsanteils — „250", nicht „250/1000". */
        public string $share,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
    ) {
    }

    public function isCurrent(DateTimeImmutable $on): bool
    {
        return (null === $this->from || $this->from <= $on)
            && (null === $this->to || $this->to >= $on);
    }
}
