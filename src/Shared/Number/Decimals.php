<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Number;

/**
 * Dezimalzahlen so schreiben und lesen, wie es die jeweilige Sprache tut.
 *
 * Das betrifft nicht nur Geld: „78.40 m²" liest sich im Deutschen falsch, und
 * „1240,00" im Englischen ebenso. Flaechen, Zimmer und Anteile gehen deshalb
 * denselben Weg wie Betraege.
 *
 * Bewusst ohne die intl-Erweiterung — dieselbe Ueberlegung wie beim Geld: das
 * haelt die Selbst-Installation frei von einer weiteren PHP-Erweiterung, und
 * bei zwei Sprachen gibt es nichts zu holen.
 *
 * Gerechnet wird hier nichts. Die Werte kommen als Zeichenkette aus der
 * Datenbank (DECIMAL) und gehen als Zeichenkette wieder hinein; Fliesskomma
 * kaeme nur herein, um Rundungsfehler mitzubringen.
 */
final class Decimals
{
    /** Tausender- und Dezimaltrenner je Sprache. */
    private const array SEPARATORS = [
        'de' => ['.', ','],
        'en' => [',', '.'],
    ];

    /** Leerzeichen, die in getippten Zahlen vorkommen — auch die schmalen. */
    private const array BLANKS = ["\u{00A0}", "\u{202F}", "\u{2009}", ' '];

    /** Eine Zahl, wie die Datenbank sie liefert. */
    private const string CANONICAL = '/^-?\d+(\.\d+)?$/D';

    private function __construct()
    {
    }

    /**
     * @return array{string, string} Tausender-, Dezimaltrenner
     */
    public static function separators(string $locale): array
    {
        // Deutsch ist die Hauptsprache und schon in der Uebersetzung der
        // Rueckfall; eine unbekannte Sprache folgt derselben Regel.
        return self::SEPARATORS[strtolower(substr($locale, 0, 2))] ?? self::SEPARATORS['de'];
    }

    public static function group(string $digits, string $separator): string
    {
        return strrev(implode($separator, str_split(strrev($digits), 3)));
    }

    /**
     * Eine Zahl aus der Datenbank, geschrieben fuer die Sprache der Anfrage.
     *
     * Nachkommastellen bleiben, wie sie dastehen: die Spalte gibt „78.40" und
     * nicht „78.4" zurueck, und zwei Stellen sind bei einer Flaeche die
     * uebliche Genauigkeit.
     *
     * `$grouped` trennt die Tausender. Nicht ueberall erwuenscht: in
     * „225,5/1000" ist der Nenner eine Skala und keine Menge, und ein Baujahr
     * ist „1974" und nicht „1.974".
     */
    public static function format(string $value, string $locale, bool $grouped = true): string
    {
        if (1 !== preg_match(self::CANONICAL, $value)) {
            // Nichts, was wir geschrieben haetten — dann steht da lieber das
            // Rohe als eine stillschweigend verfaelschte Zahl.
            return $value;
        }

        [$grouping, $point] = self::separators($locale);
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'), 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? null;

        return ($negative ? '-' : '')
            .($grouped ? self::group($whole, $grouping) : $whole)
            .(null === $fraction ? '' : $point.$fraction);
    }

    /**
     * Eine Eingabe auf die Schreibweise der Datenbank bringen.
     *
     * Getippt wird, was die Tastatur gerade hergibt: „1.240,5", „1,240.5"
     * und „1240.5" meinen dasselbe. Entschieden wird deshalb nach Bauart der
     * Zahl und nicht nach eingestellter Sprache — dieselbe Eingabe ergibt in
     * jeder Sprache denselben Wert.
     *
     * Prueft nicht, ob dabei eine gueltige Zahl herauskommt: das tut, wer den
     * Wert entgegennimmt.
     *
     * `$thirdDecimalCounts` kehrt die Tausenderregel um, wo drei
     * Nachkommastellen vorkommen: bei Verbraeuchen und Anteilen. „84,250"
     * ist dort der Zaehlerstand 84,25 und nicht vierundachtzigtausend —
     * Zaehler zeigen ihre Nachkommastellen mit an, und ein Faktor 1000 in
     * einer Abrechnung faellt niemandem auf.
     */
    public static function normalise(string $input, bool $thirdDecimalCounts = false): string
    {
        $clean = str_replace(self::BLANKS, '', trim($input));
        [$point, $grouping] = self::roles($clean, $thirdDecimalCounts);

        if (null !== $grouping) {
            $clean = str_replace($grouping, '', $clean);
        }

        return null === $point ? $clean : str_replace($point, '.', $clean);
    }

    /**
     * Welches Zeichen die Nachkommastellen abtrennt und welches die Tausender.
     *
     * Kommen beide vor, trennt das hintere die Nachkommastellen: „1.250,00"
     * und „1,250.00" sind dieselbe Zahl. Kommt nur eines vor und stehen genau
     * drei Ziffern dahinter, ist es ein Tausendertrenner — drei
     * Nachkommastellen gibt es hier nirgends, weder bei Cent noch bei
     * Flaechen noch bei Anteilen.
     *
     * Damit bleibt „1,250" mehrdeutig und wird als eintausendzweihundert-
     * fuenfzig gelesen. Dagegen steht, dass der gespeicherte Wert danach
     * sichtbar dasteht — in der Ergebnis-Vorschau eines Ablaufs oder gleich
     * im Feld selbst.
     *
     * Wo es drei Nachkommastellen wirklich gibt, kippt `$thirdDecimalCounts`
     * die Regel: dann trennt auch ein einzelnes Zeichen die Nachkommastellen.
     *
     * @return array{?string, ?string} Dezimal-, Tausendertrenner
     */
    public static function roles(string $digits, bool $thirdDecimalCounts = false): array
    {
        $dot = strrpos($digits, '.');
        $comma = strrpos($digits, ',');

        if (false !== $dot && false !== $comma) {
            return $dot > $comma ? ['.', ','] : [',', '.'];
        }

        if (false === $dot && false === $comma) {
            return [null, null];
        }

        $separator = false !== $dot ? '.' : ',';

        return self::groupsThousands($digits, $thirdDecimalCounts)
            ? [null, $separator]
            : [$separator, null];
    }

    /**
     * Ein einzelnes Zeichen mit genau drei Ziffern dahinter — und kein Feld,
     * in dem die dritte Nachkommastelle vorkommt.
     */
    private static function groupsThousands(string $digits, bool $thirdDecimalCounts): bool
    {
        return !$thirdDecimalCounts && 1 === preg_match('/[.,]\d{3}$/D', $digits);
    }
}
