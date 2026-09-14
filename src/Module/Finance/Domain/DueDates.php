<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Module\Finance\Contract\Interval;
use DateTimeImmutable;

/**
 * Wann im Wirtschaftsjahr etwas faellig wird.
 *
 * Gezaehlt wird in Monaten ab dem Beginn des Wirtschaftsjahres, nicht ab dem
 * Beginn der Staffelstufe. Sonst haette eine Stufe, die am 15. Maerz anfaengt,
 * ihre Faelligkeiten am 15. jeden Monats, und eine spaetere Stufe faenge einen
 * neuen Rhythmus an — zwei Zahlungen in einem Monat, und im naechsten Jahr
 * wieder anders.
 *
 * So bleibt es bei zwoelf Monatsersten, vier Quartalsersten oder einem Tag im
 * Jahr, und die Staffel sagt nur, wie viel an diesem Tag faellig war.
 */
final class DueDates
{
    /** Zwoelf Monate — ein Wirtschaftsjahr ist immer eines lang. */
    private const int MONTHS = 12;

    private function __construct()
    {
    }

    /**
     * Die Faelligkeiten eines Wirtschaftsjahres in diesem Rhythmus.
     *
     * @return list<DateTimeImmutable>
     */
    public static function inYear(DateTimeImmutable $yearBegins, Interval $interval): array
    {
        $every = self::monthsBetween($interval);
        $days = [];

        for ($month = 0; $month < self::MONTHS; $month += $every) {
            $days[] = $yearBegins->modify('+'.$month.' months');
        }

        return $days;
    }

    /**
     * Alle Monatsersten des Wirtschaftsjahres, der Reihe nach.
     *
     * @return list<DateTimeImmutable>
     */
    public static function monthsOf(DateTimeImmutable $yearBegins): array
    {
        return self::inYear($yearBegins, Interval::Monthly);
    }

    /** Faellt in diesem Monat des Wirtschaftsjahres eine Zahlung an? */
    public static function isDue(int $monthIndex, Interval $interval): bool
    {
        return 0 === $monthIndex % self::monthsBetween($interval);
    }

    private static function monthsBetween(Interval $interval): int
    {
        return match ($interval) {
            Interval::Monthly => 1,
            Interval::Quarterly => 3,
            Interval::Annually, Interval::Once => self::MONTHS,
        };
    }
}
