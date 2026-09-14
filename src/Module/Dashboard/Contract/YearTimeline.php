<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Contract;

use DateTimeImmutable;

/**
 * Das Jahr als Balken — fertig ausgerechnet.
 *
 * Alle Orte stehen in Prozent der Jahresbreite. Gerechnet wird das einmal
 * hier und nicht in der Vorlage: eine Vorlage, die mit Schaltjahren rechnet,
 * ist eine Vorlage, die niemand prueft.
 */
final readonly class YearTimeline
{
    /**
     * @param list<float>                           $weeks  Wochengrenzen in Prozent
     * @param list<array{label: string, at: float}> $months Monatsanfaenge
     * @param list<TimelineMark>                    $marks
     */
    public function __construct(
        public int $year,
        public DateTimeImmutable $today,
        /** Wo heute steht, in Prozent. */
        public float $at,
        public array $weeks,
        public array $months,
        public array $marks,
    ) {
    }
}
