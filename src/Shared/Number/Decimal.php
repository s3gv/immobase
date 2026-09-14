<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Number;

use InvalidArgumentException;

/**
 * Dezimalzahlen exakt addieren.
 *
 * Nicht ueber `float`: „2,5" + „1,0" ergibt dort 3.5, und wer die Summe
 * danach als Zeichenkette braucht, hat schon verloren — PHP macht aus einem
 * `float` in einer Union `string|int` eine ganze Zahl, und aus 3,5 wird 3.
 * Genau das stand einmal als Summe unter einem Verteilerschluessel.
 *
 * Dieselbe Entscheidung wie bei {@see \App\Shared\Money\Money}: gerechnet
 * wird in ganzen Zahlen, hier in Zehntausendsteln. Vier Nachkommastellen,
 * weil Anteile und Miteigentumsanteile so in der Datenbank stehen.
 */
final class Decimal
{
    /** Vier Nachkommastellen — die Genauigkeit der Spalten. */
    private const int SCALE = 10000;

    private const string CANONICAL = '/^-?\d+(\.\d{1,4})?$/D';

    private function __construct()
    {
    }

    /**
     * Die Summe kanonischer Dezimalzahlen, wieder als kanonische Dezimalzahl.
     *
     * @param list<string> $values
     *
     * @throws InvalidArgumentException
     */
    public static function sum(array $values): string
    {
        $total = 0;

        foreach ($values as $value) {
            $total += self::unitsIn($value);
        }

        return self::from($total);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function unitsIn(string $value): int
    {
        if (1 !== preg_match(self::CANONICAL, $value)) {
            throw new InvalidArgumentException('Das ist keine Dezimalzahl: '.$value);
        }

        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'), 2);
        $units = (int) $parts[0] * self::SCALE + (int) str_pad($parts[1] ?? '', 4, '0');

        return $negative ? -$units : $units;
    }

    /** Ohne ueberfluessige Nullen: „3,5" und nicht „3,5000". */
    private static function from(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        $fraction = rtrim(str_pad((string) ($units % self::SCALE), 4, '0', \STR_PAD_LEFT), '0');

        return $sign.intdiv($units, self::SCALE).('' === $fraction ? '' : '.'.$fraction);
    }
}
