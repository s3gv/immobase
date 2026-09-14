<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Verzugszinsen als Staffel — das Ergebnis, nicht ein Zwischenschritt.
 *
 * **Gerundet wird je Abschnitt, und die Summe ist die Summe der gedruckten
 * Zeilen.** Das ist nicht die mathematisch exakteste Variante — wer die
 * Aufstellung beim Amtsgericht einreicht, muss die Spalte nachaddieren
 * koennen und auf den Betrag kommen, der darunter steht. Eine Summe, die um
 * zwei Cent abweicht, ist in einem Schriftsatz ein Fehler.
 *
 * Die Tagesachse wird an drei Sorten Grenzen geschnitten:
 *
 * 1. an jeder Stufe der Forderung — eine Teilzahlung mindert den Betrag,
 * 2. an jeder Aenderung des Basiszinssatzes — 1. Januar, 1. Juli,
 * 3. an jedem Jahreswechsel — ein Schaltjahr hat 366 Nenner, ein anderes 365.
 *
 * Gezaehlt wird nach §§ 187, 188 BGB: der erste Verzugstag zaehlt, der
 * Stichtag nicht. Faellig am 03.03. heisst Verzug ab 04.03.; wer am 05.03.
 * zahlt, schuldet einen Tag.
 */
final readonly class InterestSchedule
{
    /** @param list<InterestSegment> $segments */
    private function __construct(public array $segments)
    {
    }

    /**
     * Die Staffel zu einer Forderung bis zu einem Stichtag.
     *
     * @param list<ClaimStep> $steps die Staffel des offenen Betrags, aelteste zuerst
     */
    public static function of(array $steps, BaseRates $rates, int $pointsBps, DateTimeImmutable $until): self
    {
        $segments = [];

        foreach (self::spans($steps, $until) as $span) {
            foreach (self::cut($span['from'], $span['until'], $rates) as $piece) {
                $segment = self::segment($piece['from'], $piece['until'], $span['amount'], $rates, $pointsBps);

                if (null !== $segment) {
                    $segments[] = $segment;
                }
            }
        }

        return new self($segments);
    }

    public static function nothing(): self
    {
        return new self([]);
    }

    public function total(): Money
    {
        $sum = Money::zero();

        foreach ($this->segments as $segment) {
            $sum = $sum->plus($segment->interest);
        }

        return $sum;
    }

    public function isEmpty(): bool
    {
        return [] === $this->segments;
    }

    /**
     * Die Zeitraeume gleichen Betrags — eine Stufe gilt bis zur naechsten.
     *
     * @param list<ClaimStep> $steps
     *
     * @return list<array{from: DateTimeImmutable, until: DateTimeImmutable, amount: Money}>
     */
    private static function spans(array $steps, DateTimeImmutable $until): array
    {
        $spans = [];

        foreach ($steps as $at => $step) {
            $ends = ($steps[$at + 1] ?? null)?->startsOn() ?? $until;
            $ends = $ends > $until ? $until : $ends;

            // Der Tag, an dem die naechste Stufe beginnt, gehoert ihr — der
            // Abschnitt davor endet am Vortag.
            if ($step->startsOn() < $ends && !$step->open()->isZero()) {
                $spans[] = [
                    'from' => $step->startsOn(),
                    'until' => $ends->modify('-1 day'),
                    'amount' => $step->open(),
                ];
            }
        }

        return $spans;
    }

    /**
     * Einen Zeitraum an Zinswechseln und Jahresgrenzen zerlegen.
     *
     * @return list<array{from: DateTimeImmutable, until: DateTimeImmutable}>
     */
    private static function cut(DateTimeImmutable $from, DateTimeImmutable $until, BaseRates $rates): array
    {
        $days = $rates->changesBetween($from, $until);

        for ($year = (int) $from->format('Y') + 1; $year <= (int) $until->format('Y'); ++$year) {
            $days[] = new DateTimeImmutable($year.'-01-01');
        }

        usort($days, static fn (DateTimeImmutable $one, DateTimeImmutable $other): int => $one <=> $other);

        $pieces = [];
        $start = $from;

        foreach ($days as $day) {
            if ($day <= $start) {
                continue;
            }

            $pieces[] = ['from' => $start, 'until' => $day->modify('-1 day')];
            $start = $day;
        }

        $pieces[] = ['from' => $start, 'until' => $until];

        return $pieces;
    }

    /**
     * Ein Abschnitt — null, wenn dafuer kein Basiszinssatz eingetragen ist.
     *
     * Ohne Satz wird nicht geschaetzt. Eine Luecke in der Reihe haelt die
     * Ausstellung auf ({@see BaseRates::missingFor()}); hier faellt der
     * Abschnitt still weg, damit eine Vorschau trotzdem etwas zeigen kann.
     */
    private static function segment(
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        Money $amount,
        BaseRates $rates,
        int $pointsBps,
    ): ?InterestSegment {
        $baseRate = $rates->on($from);

        if (null === $baseRate) {
            return null;
        }

        $days = (int) $from->diff($until)->days + 1;
        $rate = $baseRate + $pointsBps;
        $inYear = 1 === (int) $from->format('L') ? 366 : 365;

        return new InterestSegment(
            from: $from,
            until: $until,
            days: $days,
            amount: $amount,
            baseRateBps: $baseRate,
            pointsBps: $pointsBps,
            interest: self::interest($amount, $rate, $days, $inYear),
        );
    }

    /**
     * Zinsen auf einen Betrag, kaufmaennisch auf den Cent gerundet.
     *
     * Ganzzahlig wie der Tilgungsplan: `intdiv(2n + d, 2d)` rundet ein
     * halbes Cent nach oben. Ein negativer Satz kann rechnerisch nicht
     * herauskommen — fuenf Punkte auf −0,88 % sind 4,12 % —, aber die
     * Rechnung deckelt bei null: negative Verzugszinsen schuldet niemand,
     * und `intdiv` rundet bei negativen Zahlen in die andere Richtung.
     */
    private static function interest(Money $amount, int $rateBps, int $days, int $inYear): Money
    {
        if ($rateBps <= 0 || $days <= 0) {
            return Money::zero();
        }

        // round(x / d) als Ganzzahl: intdiv(2x + d, 2d). Hier ist
        // x = Betrag × Satz × Tage und d = 10000 × Jahrestage.
        $half = 10000 * $inYear;

        return Money::fromCents(intdiv(2 * $amount->cents() * $rateBps * $days + $half, 2 * $half));
    }
}
