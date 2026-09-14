<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Property\Contract\HouseholdWindow;
use App\Module\Tenancy\Contract\TenancySpan;
use App\Shared\Time\Segments;
use DateTimeImmutable;

/**
 * Personen mal Tage — aus zwei Quellen, ohne Widerspruch.
 *
 * Die Personenzahl steht am Mietverhaeltnis, solange eines laeuft. Fuer die
 * uebrigen Tage steht sie an der Einheit: eine selbstbewohnte Wohnung hat
 * keinen Mietvertrag, eine leerstehende auch nicht. Die Regel ist deshalb
 * tagesgenau und kennt keinen Vorrang-Zweifel — **laeuft an einem Tag ein
 * Mietverhaeltnis, zaehlt dessen Zahl, sonst die der Einheit.** Zwei Zahlen
 * fuer denselben Tag kann es nicht geben.
 *
 * Wer im Juli einzieht, zaehlt ein halbes Jahr; wer im Maerz ein Kind
 * bekommt, zaehlt ab da zu dritt. Der Stand am Silvesterabend waere
 * einfacher und falsch.
 *
 * **Ein Tag ohne Zahl macht die Angabe fehlend, nicht null.** Eine Null
 * verteilt still um: sie schoebe den Anteil der Wohnung auf die Nachbarn.
 */
final class PersonDays
{
    private function __construct()
    {
    }

    /**
     * @param list<TenancySpan>     $spans
     * @param list<HouseholdWindow> $own
     *
     * @return int|null null heisst: fuer mindestens einen Tag ist nichts erfasst
     */
    public static function of(
        array $spans,
        array $own,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?int {
        $let = self::rented($spans);
        $days = 0;
        $counted = 0;

        foreach ($spans as $span) {
            foreach ($span->persons as $step) {
                $days += $step->people * self::lengthOf($step->from, $step->to);
                $counted += self::lengthOf($step->from, $step->to);
            }
        }

        foreach ($own as $window) {
            foreach (self::whileEmpty($let, $window) as $piece) {
                $days += $window->people * self::lengthOf($piece['from'], $piece['to']);
                $counted += self::lengthOf($piece['from'], $piece['to']);
            }
        }

        return $counted >= self::lengthOf($from, $to) ? $days : null;
    }

    /**
     * Die Zeitraeume, in denen die Einheit vermietet war.
     *
     * Ob dort eine Personenzahl steht, spielt hier keine Rolle: fehlt sie am
     * Mietverhaeltnis, ist sie fehlend — und nicht durch die Zahl der Einheit
     * zu ersetzen. Sonst zaehlte der Vermieter fuer seinen Mieter mit.
     *
     * @param list<TenancySpan> $spans
     *
     * @return list<array{from: DateTimeImmutable|null, to: DateTimeImmutable|null}>
     */
    private static function rented(array $spans): array
    {
        return array_map(
            static fn (TenancySpan $span): array => ['from' => $span->from, 'to' => $span->to],
            $spans,
        );
    }

    /**
     * Die Stuecke eines Fensters, in denen niemand gemietet hatte.
     *
     * @param list<array{from: DateTimeImmutable|null, to: DateTimeImmutable|null}> $let
     *
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable}>
     */
    private static function whileEmpty(array $let, HouseholdWindow $window): array
    {
        $free = [];

        foreach (Segments::cut($let, $window->from, $window->to) as $piece) {
            if (!self::rentedOn($let, $piece['from'])) {
                $free[] = $piece;
            }
        }

        return $free;
    }

    /**
     * @param list<array{from: DateTimeImmutable|null, to: DateTimeImmutable|null}> $let
     */
    private static function rentedOn(array $let, DateTimeImmutable $day): bool
    {
        foreach ($let as $span) {
            if ((null === $span['from'] || $day >= $span['from']) && (null === $span['to'] || $day <= $span['to'])) {
                return true;
            }
        }

        return false;
    }

    private static function lengthOf(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->days + 1;
    }
}
