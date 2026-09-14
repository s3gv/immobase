<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Time;

use DateTimeImmutable;

/**
 * Aus einer Staffel werden Zeitfenster.
 *
 * Eine Staffel sagt nur, ab wann eine Stufe gilt. Das Fenster einer Stufe
 * endet am Tag vor der naechsten — und am Rand dort, wo der gefragte Zeitraum
 * endet. Eine Stufe, die vor dem Zeitraum beginnt, gilt an dessen erstem Tag
 * weiter; ihr Fenster faengt dann dort an und nicht bei ihrem eigenen Datum.
 *
 * Steht in Shared, weil es mit Miete, Kosten und Zahlungen nichts zu tun hat:
 * es ist Datumsarithmetik. Mietstufen, Haushaltsstufen und Hausgeldstufen
 * haben dasselbe Problem, und drei Kopien laufen frueher oder spaeter
 * auseinander.
 *
 * Die Stufe kommt im Fenster mit zurueck und nicht ihre Nummer: zwei Listen
 * ueber einen gemeinsamen Index zu koppeln haelt genau so lange, bis eine
 * davon anders sortiert ist.
 */
final class Windows
{
    private function __construct()
    {
    }

    /**
     * @template TStep of object
     *
     * @param list<TStep>                        $steps   zeitlich sortiert
     * @param callable(TStep): DateTimeImmutable $startOf
     *
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable, step: TStep}>
     */
    public static function within(
        array $steps,
        callable $startOf,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $windows = [];

        foreach ($steps as $at => $step) {
            $next = $steps[$at + 1] ?? null;
            $begins = self::later($startOf($step), $from);
            $ends = self::earlier(null === $next ? $to : $startOf($next)->modify('-1 day'), $to);

            if ($begins <= $ends) {
                $windows[] = ['from' => $begins, 'to' => $ends, 'step' => $step];
            }
        }

        return $windows;
    }

    public static function later(DateTimeImmutable $one, DateTimeImmutable $other): DateTimeImmutable
    {
        return $one > $other ? $one : $other;
    }

    public static function earlier(DateTimeImmutable $one, DateTimeImmutable $other): DateTimeImmutable
    {
        return $one < $other ? $one : $other;
    }
}
