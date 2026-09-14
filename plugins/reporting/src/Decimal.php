<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

use InvalidArgumentException;

/**
 * Dezimalzahlen als Ziffernfolge — nie als Gleitkommazahl.
 *
 * Betraege kommen als Zeichenkette vom Core und als NUMERIC aus der
 * Datenbank. Wer sie fuer die Anzeige in `float` umwandelt, verliert genau
 * das, was die Schnittstelle zusagt: 0,1 ist als Gleitkommazahl nicht
 * darstellbar, und bei grossen Summen rundet es an der falschen Stelle.
 * Gerechnet wird deshalb in der Datenbank; hier wird nur noch geschrieben —
 * kaufmaennisch gerundet, Ziffer fuer Ziffer.
 */
final readonly class Decimal
{
    /** „1234.5678" wird mit zwei Stellen zu „1.234,57". */
    public static function format(string $value, int $places): string
    {
        [$negative, $whole, $fraction] = self::parts($value);

        $digits = $whole.str_pad(substr($fraction, 0, $places), $places, '0');

        if ((int) ($fraction[$places] ?? '0') >= 5) {
            $digits = self::increment($digits);
        }

        $whole = ltrim(substr($digits, 0, \strlen($digits) - $places), '0');
        $grouped = strrev(implode('.', str_split(strrev('' === $whole ? '0' : $whole), 3)));
        $isZero = '' === trim($digits, '0');

        return ($negative && !$isZero ? '-' : '')
            .$grouped
            .($places > 0 ? ','.substr($digits, -$places) : '');
    }

    public static function isNegative(string $value): bool
    {
        [$negative, $whole, $fraction] = self::parts($value);

        return $negative && '' !== trim($whole.$fraction, '0');
    }

    public static function isPositive(string $value): bool
    {
        [$negative, $whole, $fraction] = self::parts($value);

        return !$negative && '' !== trim($whole.$fraction, '0');
    }

    /**
     * @return array{bool, string, string} negativ, ganzer Teil, Nachkommastellen
     */
    private static function parts(string $value): array
    {
        if (1 !== preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?\s*$/', $value, $match) || '' === $match[2].($match[3] ?? '')) {
            throw new InvalidArgumentException(\sprintf('„%s" ist keine Dezimalzahl.', $value));
        }

        return ['-' === $match[1], '' === $match[2] ? '0' : $match[2], $match[3] ?? ''];
    }

    /** Eins auf die letzte Stelle — mit Uebertrag, so weit er reicht. */
    private static function increment(string $digits): string
    {
        $at = \strlen($digits) - 1;

        while ($at >= 0 && '9' === $digits[$at]) {
            $digits[$at] = '0';
            --$at;
        }

        return $at < 0 ? '1'.$digits : substr_replace($digits, (string) ((int) $digits[$at] + 1), $at, 1);
    }
}
