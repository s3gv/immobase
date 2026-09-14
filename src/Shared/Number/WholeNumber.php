<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Number;

use InvalidArgumentException;

/**
 * Eine ganze Zahl aus einem Formularfeld — oder nichts.
 *
 * Kein (int)-Cast auf die Eingabe: der macht aus „abc" eine 0, aus „7 Stueck"
 * eine 7 und aus einer zwanzigstelligen Zahl stillschweigend PHP_INT_MAX.
 * Damit landet in der Datenbank eine Angabe, die niemand gemacht hat — und
 * die sieht hinterher aus wie eine Angabe.
 *
 * Die fachlichen Grenzen stehen nicht hier: was ein glaubwuerdiges Baujahr
 * oder eine glaubwuerdige Zahl von Stellplaetzen ist, weiss die Domaene.
 * Hier geht es nur darum, dass ueberhaupt eine Zahl dasteht.
 */
final class WholeNumber
{
    /**
     * Neun Stellen. Was laenger ist, ist keine Anzahl und kein Jahr, sondern
     * ein Vertipper oder ein Versuch — und passt ausserdem in keinen der
     * Spaltentypen, die wir dafuer benutzen.
     */
    private const string DIGITS = '/^-?\d{1,9}$/D';

    private function __construct()
    {
    }

    public static function orNull(string $input): ?int
    {
        $clean = trim($input);

        if ('' === $clean) {
            return null;
        }

        if (1 !== preg_match(self::DIGITS, $clean)) {
            throw new InvalidArgumentException(\sprintf('„%s" ist keine ganze Zahl.', $input));
        }

        return (int) $clean;
    }
}
