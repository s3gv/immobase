<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\ProposedLine;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Finance\Contract\CostRecord;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Welche Zeilen ein Empfaenger bekommt — und mit welchem Zeitanteil.
 *
 * Zwei Filter uebereinander. Der erste ist fachlich: fuer den Mieter zaehlt
 * nur, was umlagefaehig ist (§ 2 BetrKV); der Eigentuemer bekommt alles, auch
 * Verwaltervergütung und Ruecklagenzufuehrung.
 *
 * Der zweite ist zeitlich: wer im April einzieht, traegt nicht die
 * Grundsteuer des ganzen Jahres. Wo die Kostenposition tagesgenau geteilt
 * wird, wird zeitanteilig gerechnet; wo nicht, gilt der Stichtag.
 */
final class LinesForRecipient
{
    private function __construct()
    {
    }

    /**
     * @param list<CostRecord>            $costs
     * @param array<string, ProposedLine> $mine
     *
     * @return list<ProposedLine>
     */
    public static function of(
        StatementKind $kind,
        array $costs,
        array $mine,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $yearFrom,
        DateTimeImmutable $yearTo,
    ): array {
        $days = self::daysBetween($from, $to);
        $daysInYear = self::daysBetween($yearFrom, $yearTo);
        $before = self::daysBetween($yearFrom, $from) - 1;
        $lines = [];

        foreach ($costs as $cost) {
            $line = $mine[$cost->costYearId] ?? null;

            if (null === $line || ($kind->apportionableOnly() && !$cost->apportionable)) {
                continue;
            }

            $part = $cost->splitsByDay
                ? self::forDays($line, $before, $days, $daysInYear)
                : self::onTheKeyDate($line, $to, $yearTo);
            $lines = null === $part ? $lines : [...$lines, $part];
        }

        return $lines;
    }

    /**
     * Zeitanteilig — und so, dass zwei Zeitraeume zusammen wieder aufgehen.
     *
     * Der Anteil ergibt sich als Differenz zweier Summen: was bis zum Ende
     * des Zeitraums angefallen waere, minus was bis zu seinem Anfang
     * angefallen waere. Zwei aufeinanderfolgende Zeitraeume teleskopieren
     * damit exakt.
     *
     * **Jeden Zeitraum fuer sich zu runden geht schief.** Ein halbes Jahr und
     * das andere halbe ergaben so 682,03 statt 682,02 Euro — jeder bekam den
     * Rundungsrest. Ein Cent, den es nicht gibt, und niemandem faellt auf,
     * woher er kam.
     */
    private static function forDays(ProposedLine $line, int $before, int $days, int $daysInYear): ProposedLine
    {
        if ($days >= $daysInYear) {
            return $line;
        }

        return new ProposedLine(
            $line->costKind,
            $line->distribution->forDays($days, $daysInYear),
            $line->total,
            self::part($line->amount, $before, $days, $daysInYear),
            $line->totalInputTax,
            // Dieselbe Teilung fuer die Steuer darin: zwei Zeitraeume ergeben
            // auch hier zusammen genau das Ganze.
            self::part($line->inputTax, $before, $days, $daysInYear),
        );
    }

    private static function part(Money $whole, int $before, int $days, int $daysInYear): Money
    {
        $cents = $whole->cents();
        $upToEnd = intdiv($cents * min($before + $days, $daysInYear), $daysInYear);
        $upToStart = intdiv($cents * min($before, $daysInYear), $daysInYear);

        return Money::fromCents($upToEnd - $upToStart);
    }

    /**
     * Stichtag: es trifft den, der am letzten Tag des Jahres da war.
     *
     * Ein Posten, der nicht tagesgenau geteilt wird, laesst sich nicht
     * halbieren — die Kaminkehrergebuehr faellt einmal an, und sie faellt bei
     * dem an, der dann dort wohnt.
     */
    private static function onTheKeyDate(
        ProposedLine $line,
        DateTimeImmutable $lastDayOfPeriod,
        DateTimeImmutable $lastDayOfYear,
    ): ?ProposedLine {
        return $lastDayOfPeriod >= $lastDayOfYear ? $line : null;
    }

    private static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->days + 1;
    }
}
