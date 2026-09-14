<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use RuntimeException;

/**
 * Abwickeln ohne Stichtag gibt es nicht.
 *
 * Der Tag entscheidet, bis wann abgerechnet wird und wann die
 * Mietverhaeltnisse enden. Ohne ihn waere die Abwicklung eine Behauptung
 * ohne Datum — und jede spaetere Abrechnung Raterei.
 */
final class PropertyNeedsAClosingDate extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Zum Abwickeln gehört ein Stichtag.');
    }
}
