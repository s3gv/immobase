<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use InvalidArgumentException;

/**
 * Ein Verteilungsgewicht als ganze Zahl.
 *
 * Flaechen, Miteigentumsanteile, Verbraeuche und feste Anteile kommen als
 * Dezimalzeichenketten mit bis zu drei Nachkommastellen. Zum Verteilen
 * braucht {@see \App\Shared\Money\Money::allocate()} ganze Zahlen — also
 * werden sie mit tausend genommen.
 *
 * **Ohne Fliesskomma.** `(int) round((float) '0.1' * 1000)` sieht harmlos aus
 * und ist genau die Stelle, an der eine Abrechnung um einen Cent danebenliegt.
 * Hier wird die Zeichenkette zerlegt und wieder zusammengesetzt: das ist
 * exakt, und es bleibt exakt.
 */
final class Weight
{
    /** Drei Nachkommastellen — so genau sind die Spalten. */
    private const int SCALE = 1000;
    private const string SHAPE = '/^(?<sign>-?)(?<whole>\d+)(?:\.(?<fraction>\d{1,3})\d*)?$/D';

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function of(string $decimal): int
    {
        $trimmed = trim($decimal);

        if (1 !== preg_match(self::SHAPE, $trimmed, $match)) {
            throw new InvalidArgumentException('„'.$decimal.'" ist keine Zahl, die sich verteilen lässt.');
        }

        $fraction = str_pad($match['fraction'] ?? '', 3, '0');
        $value = (int) ($match['whole'].$fraction);

        return '-' === $match['sign'] ? -$value : $value;
    }

    /** Eine ganze Zahl als Gewicht — Personen, Einheiten, Tage. */
    public static function ofCount(int $count): int
    {
        return $count * self::SCALE;
    }
}
