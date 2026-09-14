<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

use App\Shared\Number\Decimals;

/**
 * Liest einen getippten Geldbetrag.
 *
 * Punkt und Komma tauschen zwischen den Sprachen die Rollen, und Menschen
 * tippen ohnehin, was ihre Tastatur gerade hergibt. Deshalb wird nicht nach
 * eingestellter Sprache entschieden, sondern nach Bauart der Zahl — dieselbe
 * Eingabe ergibt in jeder Sprache denselben Betrag.
 *
 * Waehrungszeichen und Leerzeichen sind erlaubt: wer einen Betrag aus einer
 * Rechnung kopiert, soll ihn einfach einfuegen koennen.
 *
 * Welches Zeichen die Nachkommastellen abtrennt, entscheidet Decimals::roles()
 * — dieselbe Regel gilt fuer Flaechen und Anteile.
 */
final class MoneyInput
{
    private const AMOUNT = '/^(?<sign>[+-]?)(?<digits>[\d.,]+)$/D';

    private function __construct()
    {
    }

    /**
     * Leere Eingabe heisst „nicht angegeben" und ist kein Fehler.
     *
     * Der Unterschied zu einer Null ist einer, der zaehlt: „nichts gezahlt"
     * und „keine Angabe" sehen im Feld gleich aus, und wer beides gleich
     * behandelt, verliert die Unterscheidung an der Stelle, an der sie
     * getroffen wird.
     *
     * @throws UnreadableAmount
     */
    public static function orNull(string $input): ?Money
    {
        return '' === trim($input) ? null : self::parse($input);
    }

    /**
     * @throws UnreadableAmount
     */
    public static function parse(string $input): Money
    {
        if (1 !== preg_match(self::AMOUNT, self::withoutNoise($input), $match)) {
            throw UnreadableAmount::of($input);
        }

        [$whole, $fraction] = self::split($match['digits'], $input);

        return Money::fromCents(self::toCents($whole, $fraction, '-' === $match['sign'], $input));
    }

    /**
     * Setzt die Cent-Zahl aus den Ziffern zusammen.
     *
     * Zusammengesetzt statt gerechnet: "1250" und "00" ergeben "125000".
     * Eine Multiplikation mit hundert liefe bei langen Eingaben in
     * Fliesskomma ueber, und die Umwandlung nach int saettigt stumm bei
     * PHP_INT_MAX. Beides ergaebe einen falschen Betrag statt einer
     * Fehlermeldung — bei Geld die schlechteste aller Varianten.
     *
     * Nach unten reicht der Bereich einen Cent weiter als nach oben. Diese
     * Schiefe kommt aus dem Zweierkomplement und ist keine Feinheit: die
     * Anzeige kann jeden Money-Wert schreiben, also muss die Eingabe jeden
     * zurueckelesen koennen. Sonst gilt die Zusage der beiden Klassen mit
     * einer Ausnahme, und Ausnahmen muss sich jeder spaetere Aufrufer merken.
     */
    private static function toCents(string $whole, string $fraction, bool $negative, string $input): int
    {
        $digits = ltrim($whole.$fraction, '0');

        if ('' === $digits) {
            return 0;
        }

        // Aus PHP_INT_MIN abgelesen statt ausgerechnet: das bleibt auch dann
        // richtig, wenn PHP anderswo mit anderer Integer-Breite laeuft.
        $limit = ltrim((string) ($negative ? \PHP_INT_MIN : \PHP_INT_MAX), '-');

        if (self::exceeds($digits, $limit)) {
            throw UnreadableAmount::outOfRange($input);
        }

        if (!$negative) {
            return (int) $digits;
        }

        // Genau am unteren Rand hilft kein Vorzeichenwechsel: der Betrag
        // allein passt in keinen Integer, die Umwandlung saettigt bei
        // PHP_INT_MAX und ergaebe einen um einen Cent falschen Betrag.
        return $digits === $limit ? \PHP_INT_MIN : -(int) $digits;
    }

    /**
     * Vergleicht zwei vorzeichenlose Ziffernfolgen der Groesse nach.
     *
     * strcmp und nicht der Vergleichsoperator: PHP deutet zwei
     * Ziffernfolgen als Zahlen und verliert genau an dieser Grenze die
     * Genauigkeit.
     */
    private static function exceeds(string $digits, string $limit): bool
    {
        if (\strlen($digits) !== \strlen($limit)) {
            return \strlen($digits) > \strlen($limit);
        }

        return strcmp($digits, $limit) > 0;
    }

    private static function withoutNoise(string $input): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]|€|EUR/iu', '', $input);
    }

    /**
     * Zerlegt in Vor- und zweistelligen Nachkommateil, beide als Ziffernfolge.
     *
     * @return array{string, string}
     */
    private static function split(string $digits, string $input): array
    {
        [$decimal, $grouping] = Decimals::roles($digits);

        if (null === $decimal) {
            return [self::wholeDigits($digits, $grouping, $input), '00'];
        }

        $position = strrpos($digits, $decimal);
        $fraction = false === $position ? '' : substr($digits, $position + 1);

        if (1 !== preg_match('/^\d{1,2}$/D', $fraction)) {
            throw UnreadableAmount::of($input);
        }

        $whole = false === $position ? '' : substr($digits, 0, $position);

        // "12,5" sind fuenfzig Cent, nicht fuenf.
        return [self::wholeDigits($whole, $grouping, $input), str_pad($fraction, 2, '0')];
    }

    private static function wholeDigits(string $digits, ?string $grouping, string $input): string
    {
        // Entweder sauber gruppiert oder gar nicht — nichts dazwischen.
        // "1.250" ist ein Betrag, "1.2.3" ist ein Vertipper.
        $pattern = match ($grouping) {
            '.' => '/^(\d{1,3}(\.\d{3})*|\d*)$/D',
            ',' => '/^(\d{1,3}(,\d{3})*|\d*)$/D',
            default => '/^\d*$/D',
        };

        if (1 !== preg_match($pattern, $digits)) {
            throw UnreadableAmount::of($input);
        }

        return str_replace(['.', ','], '', $digits);
    }
}
