<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

use DateTimeImmutable;

/**
 * Wie viele Menschen in einem Zeitabschnitt in der Einheit gelebt haben —
 * ohne Mietvertrag.
 *
 * Das Gegenstueck zur Personenstaffel des Mietverhaeltnisses, fuer die Tage,
 * an denen keines laeuft: selbst bewohnt oder leer stehend. Wer beide
 * Quellen zusammenlegt, muss dazu nichts entscheiden — die Zeitraeume
 * ueberschneiden sich nicht.
 */
final readonly class HouseholdWindow
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $people,
    ) {
    }
}
