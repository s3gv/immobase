<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Eine Erhaltungsruecklage gibt es nur bei WEG-Verwaltung.
 *
 * Sie gehoert der Gemeinschaft und liegt auf deren Konto (§ 19 Abs. 2 Nr. 4
 * WEG). Bei reiner Mietverwaltung gibt es keine Gemeinschaft, und bei
 * Sondereigentumsverwaltung verwaltet man das Sondereigentum eines
 * Eigentuemers — der zahlt Hausgeld ein, das jemand anderes fuehrt.
 */
final class ReserveOnlyForWeg extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Eine Erhaltungsrücklage gibt es nur bei WEG-Verwaltung.');
    }
}
