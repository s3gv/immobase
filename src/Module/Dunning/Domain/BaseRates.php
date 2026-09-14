<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use DateTimeImmutable;

/**
 * Die Reihe der Basiszinssaetze — und was sie an einem Tag sagt.
 *
 * Ein Satz gilt, bis der naechste ihn abloest. Die Reihe beantwortet damit
 * zwei Fragen: welcher Satz galt an diesem Tag, und an welchen Tagen wechselt
 * er innerhalb eines Zeitraums. Die zweite ist der Grund, warum die
 * Zinsstaffel Abschnitte hat.
 */
final readonly class BaseRates
{
    /** @param list<BaseRate> $rates */
    private function __construct(private array $rates)
    {
    }

    /**
     * @param list<BaseRate> $rates in beliebiger Reihenfolge
     */
    public static function of(array $rates): self
    {
        usort(
            $rates,
            static fn (BaseRate $one, BaseRate $other): int => $one->validFrom() <=> $other->validFrom(),
        );

        return new self($rates);
    }

    /**
     * Der Satz an diesem Tag — null, wenn die Reihe dort nichts sagt.
     *
     * Null heisst nicht „null Prozent". Es heisst, dass niemand den Satz
     * eingetragen hat, und darauf laesst sich keine Zinsforderung stuetzen.
     */
    public function on(DateTimeImmutable $day): ?int
    {
        $found = null;

        foreach ($this->rates as $rate) {
            if ($rate->validFrom() <= $day) {
                $found = $rate->rateBps();
            }
        }

        return $found;
    }

    /**
     * Die Tage, an denen sich der Satz innerhalb des Zeitraums aendert.
     *
     * Der erste Tag zaehlt nicht mit — an ihm faengt der erste Abschnitt an
     * und hoert nicht auf.
     *
     * @return list<DateTimeImmutable> aufsteigend
     */
    public function changesBetween(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $days = [];

        foreach ($this->rates as $rate) {
            if ($rate->validFrom() > $from && $rate->validFrom() <= $until) {
                $days[] = $rate->validFrom();
            }
        }

        return $days;
    }

    /** @return list<BaseRate> aufsteigend nach Gueltigkeitstag */
    public function all(): array
    {
        return $this->rates;
    }

    public function isEmpty(): bool
    {
        return [] === $this->rates;
    }

    /** Der juengste eingetragene Tag — fuer den Hinweis, dass die Reihe stehengeblieben ist. */
    public function latest(): ?DateTimeImmutable
    {
        $last = $this->rates[\count($this->rates) - 1] ?? null;

        return $last?->validFrom();
    }

    /**
     * Fehlt der Satz fuer das Halbjahr, in das dieser Tag faellt?
     *
     * Die Frage, die die Ausstellung aufhaelt. Ohne sie rechnete die
     * Anwendung ab dem 1. Januar still mit einem veralteten Satz weiter, und
     * das fiele erst vor Gericht auf.
     */
    public function missingFor(DateTimeImmutable $day): bool
    {
        $latest = $this->latest();

        return null === $latest || $latest < self::halfYearOf($day);
    }

    /** Der erste Tag des Halbjahres, in dem dieser Tag liegt. */
    public static function halfYearOf(DateTimeImmutable $day): DateTimeImmutable
    {
        $month = (int) $day->format('n') <= 6 ? '01' : '07';

        return new DateTimeImmutable($day->format('Y').'-'.$month.'-01');
    }
}
