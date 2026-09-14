<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DateTimeImmutable;

/**
 * In welches Wirtschaftsjahr ein Tag faellt.
 *
 * Die Regel steht am Objekt: Tag und Monat, an denen das Jahr beginnt. Faengt
 * es am 1. Juli an, gehoert der 15. Maerz 2027 zum Wirtschaftsjahr **2026** —
 * ein Jahr heisst nach dem Jahr, in dem es beginnt.
 *
 * Fuer die Sonderumlage: ihr Faelligkeitstag entscheidet, in welchem Jahr sie
 * auf der Zahlungsseite steht. Die Kalenderjahreszahl zu nehmen waere bei
 * jedem abweichenden Wirtschaftsjahr daneben.
 */
final class FiscalYear
{
    private function __construct()
    {
    }

    public static function of(DateTimeImmutable $day, int $startsOnDay, int $startsInMonth): int
    {
        $year = (int) $day->format('Y');
        $start = new DateTimeImmutable(\sprintf('%04d-%02d-%02d', $year, $startsInMonth, $startsOnDay));

        return $day < $start ? $year - 1 : $year;
    }
}
