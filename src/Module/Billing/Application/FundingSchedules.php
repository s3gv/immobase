<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Funding;
use App\Module\Finance\Contract\Interval;
use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use App\Shared\Money\RepaymentPlan;
use DateTimeImmutable;

/**
 * Aus einer Finanzierung werden Termine und Betraege.
 *
 * Drei Ableitungen, die alle dasselbe Muster haben: eine Summe und eine Zahl,
 * ueber die sie sich verteilt — und jedes Mal exakt. `Money::allocate()` sorgt
 * dafuer, dass drei Raten zusammen die Sonderumlage sind und fuenf
 * Sparjahre zusammen der Sparbetrag; der verbleibende Cent geht der Reihe
 * nach und nicht verloren.
 */
final class FundingSchedules
{
    private function __construct()
    {
    }

    /**
     * Die Faelligkeiten der Sonderumlage.
     *
     * Ohne ersten Tag gibt es keine: der Beschluss muss ihn nennen, sonst ist
     * die Sonderumlage nicht faellig.
     *
     * @return list<DateTimeImmutable>
     */
    public static function levyDates(Funding $funding): array
    {
        $first = $funding->levyDueOn();

        if (null === $first || $funding->levy()->isZero()) {
            return [];
        }

        $dates = [];

        for ($part = 0; $part < $funding->levyParts(); ++$part) {
            $dates[] = 0 === $part ? $first : $first->modify(self::step($funding->levyInterval(), $part));
        }

        return $dates;
    }

    /**
     * Eine Sonderumlage in Raten — gleich gross, der Rest auf der letzten.
     *
     * `Money::allocate()` verteilt den Rest auf die **ersten** Teile; das ist
     * beim Verteilen auf Einheiten richtig, weil dort die Reihenfolge der
     * Einheitennummern entscheidet und jeder Cent einen Empfaenger braucht.
     * Bei Raten derselben Zahlung ist es eine Zumutung: „die ersten zwei von
     * acht Raten sind einen Cent hoeher" laesst sich weder sagen noch merken.
     *
     * Darum hier die Regel, die auch eine Verwaltung schreiben wuerde: **acht
     * Raten zu 1.576,39 Euro, die letzte 1.576,33.** Exakt bleibt es trotzdem
     * — die letzte Rate ist der Rest, nicht ein gerundeter Wert.
     *
     * @return non-empty-list<Money>
     */
    public static function instalments(Money $amount, int $parts): array
    {
        if ($parts < 2) {
            return [$amount];
        }

        $each = intdiv($amount->cents(), $parts);
        $rates = array_fill(0, $parts - 1, Money::fromCents($each));
        $rates[] = Money::fromCents($amount->cents() - $each * ($parts - 1));

        return $rates;
    }

    /**
     * Die Zufuehrung je Jahr.
     *
     * @return array<int, Money>
     */
    public static function savingPerYear(Funding $funding): array
    {
        $years = $funding->savingYears();

        if ($years < 1 || $funding->saving()->isZero()) {
            return [];
        }

        $parts = $funding->saving()->allocate(array_fill(0, $years, 1));
        $perYear = [];

        foreach ($parts as $at => $part) {
            $perYear[$funding->savingFrom() + $at] = $part;
        }

        return $perYear;
    }

    /**
     * Der Tilgungsplan des geplanten Darlehens — null, wenn keines vorgesehen ist.
     *
     * @throws LoanDoesNotAmortise
     */
    public static function repayment(Funding $funding): ?RepaymentPlan
    {
        if ($funding->loan()->isZero()) {
            return null;
        }

        $payment = $funding->loanPayment();

        if (null !== $payment) {
            return RepaymentPlan::withPayment($funding->loan(), $funding->loanRateBps(), $payment);
        }

        return RepaymentPlan::overMonths($funding->loan(), $funding->loanRateBps(), $funding->loanMonths() ?? 0);
    }

    /** Der Abstand zur ersten Faelligkeit — „+2 months" bei der zweiten Vierteljahresrate. */
    private static function step(Interval $interval, int $part): string
    {
        $months = match ($interval) {
            Interval::Monthly => 1,
            Interval::Quarterly => 3,
            Interval::Annually => 12,
            Interval::Once => 0,
        };

        return \sprintf('+%d months', $months * $part);
    }
}
