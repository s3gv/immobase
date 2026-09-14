<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Api;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Wie Werte ueber die Grenze gehen.
 *
 * An einer Stelle, damit „Betrag" in jeder Ressource dasselbe heisst. Sonst
 * traegt die eine Cent und die naechste Euro, und ein Plugin rechnet mit dem
 * Hundertfachen.
 */
final readonly class ApiValue
{
    /**
     * Geld als Dezimalzeichenkette, nie als Gleitkommazahl.
     *
     * JSON kennt nur `number`, und das ist in den meisten Sprachen ein
     * Double. 1234.56 ist darin nicht darstellbar — eine Auswertung, die
     * damit summiert, geht nicht auf, und zwar erst ab der vierten Stelle.
     *
     * @return array{amount: string, currency: string}
     */
    public static function money(Money $amount): array
    {
        return ['amount' => $amount->toDecimal(), 'currency' => 'EUR'];
    }

    /** ISO-8601 mit Zone — ein Zeitpunkt ohne Zone ist eine Vermutung. */
    public static function moment(?DateTimeImmutable $at): ?string
    {
        return $at?->format(DateTimeImmutable::ATOM);
    }

    /** Ein Tag ohne Uhrzeit: ein Mietbeginn hat keine. */
    public static function day(?DateTimeImmutable $day): ?string
    {
        return $day?->format('Y-m-d');
    }
}
