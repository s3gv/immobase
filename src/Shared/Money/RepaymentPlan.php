<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * Ein Tilgungsplan — Monat fuer Monat, in ganzen Cent.
 *
 * **Wir rechnen keine Formel, wir rechnen den Plan.** Die Annuitaetenformel
 * braucht Potenzen; Potenzen brauchen Fliesskomma oder eine Bibliothek, die
 * es hier nicht gibt. Der Plan dagegen ist eine Schleife aus zwei
 * Grundrechenarten: Zins auf die Restschuld, der Rest ist Tilgung. Er ist
 * damit die Wahrheit, und Rate, Gesamtzins und Restschuld sind Ausz&uuml;ge
 * daraus — nicht umgekehrt.
 *
 * Gerundet wird kaufmaennisch auf den Cent, so wie eine Bank es tut. Die
 * letzte Rate ist die Restschuld plus ihr Zins und faellt darum fast immer
 * aus der Reihe; das ist kein Fehler, sondern der Grund, warum ein Plan auf
 * null aufgeht.
 *
 * Der Zinssatz steht in **Basispunkten**: 4,20 % sind 420. Ein Prozentsatz
 * als Dezimalzahl waere hier genau die Zahl, die man nicht rechnen kann.
 */
final readonly class RepaymentPlan
{
    /**
     * @param non-empty-list<Repayment> $schedule
     */
    private function __construct(
        public Money $principal,
        public int $rateBps,
        public Money $payment,
        /**
         * Der Plan selbst, Monat fuer Monat.
         *
         * Heisst `schedule` und nicht `months`: {@see months()} ist die
         * Anzahl, und eine Vorlage, die beides unter demselben Namen findet,
         * nimmt das Falsche.
         *
         * @var non-empty-list<Repayment>
         */
        public array $schedule,
    ) {
    }

    /**
     * Der Plan zu einer bekannten Rate — der uebliche Fall, die Bank nennt sie.
     *
     * Aenderungen unterwegs kommen als {@see PlanChange} mit: eine
     * Sondertilgung verkuerzt den Plan, ein neuer Zins verschiebt das
     * Verhaeltnis von Zins und Tilgung. Beides wird **gerechnet** und nicht
     * nachgetragen — darum bleibt der Plan die Wahrheit.
     *
     * @param list<PlanChange> $changes
     *
     * @throws LoanDoesNotAmortise
     */
    public static function withPayment(Money $principal, int $rateBps, Money $payment, array $changes = []): self
    {
        $months = Amortisation::run($principal->cents(), $rateBps, $payment->cents(), Amortisation::MOST_MONTHS, $changes);

        if (null === $months) {
            throw LoanDoesNotAmortise::theRateIsTooSmall();
        }

        return new self($principal, $rateBps, $payment, $months);
    }

    /**
     * Der Plan zu einer gewuenschten Laufzeit — die Rate wird gesucht.
     *
     * Gesucht wird die kleinste Rate, mit der der Plan in der Laufzeit auf
     * null geht: eine Intervallschachtelung ueber ganze Cent. Hoehere Rate
     * heisst kuerzere Laufzeit, darum laesst sie sich halbieren.
     *
     * @throws LoanDoesNotAmortise
     */
    public static function overMonths(Money $principal, int $rateBps, int $months): self
    {
        if ($months < 1 || $months > Amortisation::MOST_MONTHS) {
            throw LoanDoesNotAmortise::theTermIsImpossible();
        }

        $low = intdiv($principal->cents(), $months);
        $high = $principal->cents() + Amortisation::interestOn($principal->cents(), $rateBps);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (null === Amortisation::run($principal->cents(), $rateBps, $middle, $months)) {
                $low = $middle + 1;

                continue;
            }

            $high = $middle;
        }

        return self::withPayment($principal, $rateBps, Money::fromCents($low));
    }

    public function months(): int
    {
        return \count($this->schedule);
    }

    public function first(): Repayment
    {
        return $this->schedule[0];
    }

    /** Die letzte Rate — sie ist die Restschuld plus ihr Zins und faellt aus der Reihe. */
    public function last(): Repayment
    {
        $schedule = $this->schedule;

        return end($schedule);
    }

    public function totalInterest(): Money
    {
        $sum = Money::zero();

        foreach ($this->schedule as $month) {
            $sum = $sum->plus($month->interest);
        }

        return $sum;
    }

    /** Was insgesamt zurueckfliesst — Tilgung und Zins. */
    public function total(): Money
    {
        return $this->principal->plus($this->totalInterest());
    }

    /**
     * Was in einem Abschnitt des Plans faellig wird.
     *
     * Fuer die Jahresbelastung: Monat 1 bis 12 ist das erste Jahr. Ueber das
     * Ende hinaus faellt nichts mehr an.
     */
    public function between(int $firstMonth, int $count): Money
    {
        $sum = Money::zero();

        foreach ($this->schedule as $month) {
            if ($month->month >= $firstMonth && $month->month < $firstMonth + $count) {
                $sum = $sum->plus($month->payment);
            }
        }

        return $sum;
    }
}
