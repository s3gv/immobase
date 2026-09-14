<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

use App\Shared\Number\Decimals;

/**
 * Schreibt einen Geldbetrag so, wie er in der jeweiligen Sprache gelesen wird.
 *
 * Gerechnet wird durchgaengig in ganzen Cent, und auch hier wird nicht durch
 * Fliesskomma gegangen: der Betrag wird als Ziffernfolge zusammengesetzt.
 *
 * Bewusst ohne die intl-Erweiterung. Das haelt die Selbst-Installation frei
 * von einer weiteren PHP-Erweiterung, und es gibt nichts zu holen: das System
 * kennt zwei Sprachen und eine Waehrung. intl wuerde im Englischen ausserdem
 * "€1,250.00" schreiben — das Waehrungszeichen gehoert hier hinter den Betrag.
 */
final class MoneyFormatter
{
    /**
     * Geschuetztes Leerzeichen: Betrag und Zeichen gehoeren zusammen und
     * duerfen nicht ueber einen Zeilenumbruch getrennt werden.
     */
    private const CURRENCY = "\u{00A0}€";

    private function __construct()
    {
    }

    public static function format(Money $money, string $locale): string
    {
        return self::formatNumber($money, $locale).self::CURRENCY;
    }

    /**
     * Der blanke Betrag ohne Waehrungszeichen — fuer Eingabefelder.
     */
    public static function formatNumber(Money $money, string $locale): string
    {
        [$grouping, $decimal] = Decimals::separators($locale);

        $cents = (string) $money->cents();
        $negative = str_starts_with($cents, '-');

        // Ohne abs(): der kleinstmoegliche Integer hat keinen positiven
        // Gegenwert, abs() liefert dort eine Fliesskommazahl und intdiv()
        // nimmt keine. Drei Stellen sind das Mindeste, damit fuenf Cent zu
        // "005" und damit zu "0,05" werden.
        $digits = str_pad(ltrim($cents, '-'), 3, '0', \STR_PAD_LEFT);

        return ($negative ? '-' : '')
            .Decimals::group(substr($digits, 0, -2), $grouping)
            .$decimal
            .substr($digits, -2);
    }
}
