<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Time;

use DateTimeImmutable;

/**
 * Ein Zeitraum, zerschnitten an den Raendern anderer Zeitraeume.
 *
 * Die Frage dahinter: „Wer gehoert wann dazu?", wenn mehrere Zeitraeume
 * durcheinander anfangen und aufhoeren. Eine Wohnung gehoert dem Ehepaar bis
 * zum 30. Juni; ab dem 1. Juli haelt der Kaeufer die Haelfte des Mannes, die
 * Frau bleibt. Das Jahr zerfaellt damit in zwei Abschnitte, und in jedem ist
 * die Eigentuemerschaft eine andere.
 *
 * Geschnitten wird an jedem Anfang und an jedem Tag nach einem Ende — mehr
 * Schnitte gibt es nicht, denn dazwischen aendert sich nichts. Heraus kommt
 * eine lueckenlose Folge, die den ganzen Zeitraum abdeckt: was in einem
 * Abschnitt gilt, entscheidet der Aufrufer.
 *
 * Steht in Shared, weil es Datumsarithmetik ist. {@see Windows} loest das
 * verwandte Problem einer **Staffel**, bei der jede Stufe bis zur naechsten
 * gilt; hier liegen die Zeitraeume nebeneinander und duerfen sich
 * ueberlappen.
 */
final class Segments
{
    private function __construct()
    {
    }

    /**
     * @param list<array{from: DateTimeImmutable|null, to: DateTimeImmutable|null}> $intervals
     *
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable}> zeitlich sortiert, lueckenlos
     */
    public static function cut(array $intervals, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to) {
            return [];
        }

        $cuts = self::cutsWithin($intervals, $from, $to);
        $segments = [];

        foreach ($cuts as $at => $begins) {
            $next = $cuts[$at + 1] ?? null;
            $ends = null === $next ? $to : $next->modify('-1 day');

            if ($begins <= $ends) {
                $segments[] = ['from' => $begins, 'to' => $ends];
            }
        }

        return $segments;
    }

    /**
     * Die Tage, an denen sich etwas aendern kann — der erste immer dabei.
     *
     * Ein Ende schneidet am Tag **danach**: am Ende selbst gilt der Zeitraum
     * noch. Ohne diesen Tag gehoerte der letzte Tag schon dem Nachfolger.
     *
     * @param list<array{from: DateTimeImmutable|null, to: DateTimeImmutable|null}> $intervals
     *
     * @return list<DateTimeImmutable> aufsteigend, ohne Dopplungen
     */
    private static function cutsWithin(array $intervals, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = [$from->format('Y-m-d') => $from];

        foreach ($intervals as $interval) {
            foreach (self::edgesOf($interval) as $day) {
                if ($day > $from && $day <= $to) {
                    $days[$day->format('Y-m-d')] = $day;
                }
            }
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * @param array{from: DateTimeImmutable|null, to: DateTimeImmutable|null} $interval
     *
     * @return list<DateTimeImmutable>
     */
    private static function edgesOf(array $interval): array
    {
        $edges = [];

        if (null !== $interval['from']) {
            $edges[] = $interval['from'];
        }

        if (null !== $interval['to']) {
            $edges[] = $interval['to']->modify('+1 day');
        }

        return $edges;
    }
}
