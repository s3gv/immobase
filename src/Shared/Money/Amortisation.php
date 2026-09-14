<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * Die Rechnung hinter dem Tilgungsplan: eine Schleife, Monat fuer Monat.
 *
 * Steht neben {@see RepaymentPlan} und nicht darin, weil es zwei Dinge sind:
 * der Plan ist das **Ergebnis** und wird gelesen — Rate, Laufzeit, Zinsen,
 * Restschuld. Hier wird es **ausgerechnet**, und dieser Teil hat mit Lesen
 * nichts zu tun.
 *
 * Alles in ganzen Cent, alles in ganzen Zahlen. Keine Potenz, kein
 * Fliesskomma, keine Formel.
 */
final class Amortisation
{
    /** Laenger plant niemand — und eine Suche muss irgendwo aufhoeren. */
    public const int MOST_MONTHS = 600;

    private function __construct()
    {
    }

    /**
     * Der Plan, Monat fuer Monat.
     *
     * Null heisst: mit dieser Rate geht er nicht auf — sie deckt nicht einmal
     * den Zins, oder sie braucht laenger als erlaubt.
     *
     * @param list<PlanChange> $changes
     *
     * @return non-empty-list<Repayment>|null
     */
    public static function run(
        int $principal,
        int $rateBps,
        int $payment,
        int $limit = self::MOST_MONTHS,
        array $changes = [],
    ): ?array {
        $balance = $principal;
        $months = [];

        for ($month = 1; $month <= $limit; ++$month) {
            $interest = self::interestOn($balance, $rateBps);
            $due = min($payment, $balance + $interest);

            if ($due <= $interest && $balance > 0) {
                return null;
            }

            $left = $balance - ($due - $interest);
            [$balance, $rateBps, $extra] = self::changedAfter($month, $changes, $left, $rateBps);
            $months[] = self::month($month, $due, $interest, $balance, $extra);

            if (0 === $balance) {
                return $months;
            }
        }

        return null;
    }

    /**
     * Der Zins eines Monats, kaufmaennisch gerundet.
     *
     * `Restschuld × Satz ÷ 12`, alles in ganzen Zahlen: der Satz steht in
     * Basispunkten, also ist der Nenner 10.000 × 12. Die halbe Einheit oben
     * drauf rundet auf, was ueber der Haelfte liegt.
     */
    public static function interestOn(int $balance, int $rateBps): int
    {
        return intdiv($balance * $rateBps + 60000, 120000);
    }

    private static function month(int $month, int $due, int $interest, int $balance, int $extra): Repayment
    {
        return new Repayment(
            $month,
            Money::fromCents($due),
            Money::fromCents($interest),
            Money::fromCents($due - $interest),
            Money::fromCents($balance),
            0 === $extra ? null : Money::fromCents($extra),
        );
    }

    /**
     * Was eine Aenderung nach diesem Monat bewirkt.
     *
     * Die Sondertilgung geht sofort von der Restschuld ab und steht in
     * derselben Zeile: so liest es auch ein Kontoauszug. Mehr als die
     * Restschuld tilgt sie nicht — dann ist das Darlehen abgeloest, und der
     * Plan endet dort.
     *
     * @param list<PlanChange> $changes
     *
     * @return array{int, int, int} Restschuld, Zinssatz und die Sondertilgung
     */
    private static function changedAfter(int $month, array $changes, int $balance, int $rateBps): array
    {
        $extra = 0;

        foreach ($changes as $change) {
            if ($change->afterMonth === $month) {
                $extra += min($balance - $extra, $change->extra?->cents() ?? 0);
                $rateBps = $change->rateBps ?? $rateBps;
            }
        }

        return [$balance - $extra, $rateBps, $extra];
    }
}
