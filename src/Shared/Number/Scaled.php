<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Number;

use InvalidArgumentException;

/**
 * Skalierte Ganzzahlen — Cent, Hundertstel, Tausendstel.
 *
 * Wer exakt rechnen will, rechnet in ganzen Zahlen: Geld in Cent, ein
 * Miteigentumsanteil in Hundertsteln, ein Verteilergewicht in Tausendsteln.
 * Irgendwann soll die Zahl aber dastehen, und dann braucht sie ihr Komma
 * zurueck.
 *
 * Steht neben {@see Decimals} und nicht darin: dort geht es darum, wie eine
 * Sprache Zahlen schreibt, hier darum, wie eine Skala zur Zahl wird. Zwei
 * Fragen, zwei Klassen.
 */
final class Scaled
{
    /** Mehr Stellen hat keine Skala, die in eine Ganzzahl passt. */
    private const int MOST_DECIMALS = 18;

    private function __construct()
    {
    }

    /**
     * Eine skalierte Ganzzahl als Dezimalzahl — ohne Fliesskomma.
     *
     * Cent zu Euro, Hundertstel zu Anteil: der uebliche Weg `$scaled / 100`
     * ist eine Division und damit ein Fliesskommawert. Bis etwa neun
     * Billiarden geht das gut, darueber nicht mehr: 9007199254740993 Cent
     * ergaeben so „90071992547409.92" statt „…93". Ein Cent, den es nicht
     * gibt — und wenn die Zahl ein Verteilerschluessel ist, verteilt er sich
     * weiter.
     *
     * Ganzzahlig geht es immer: der ganze Teil ist eine Division mit Rest,
     * der Rest sind die Nachkommastellen.
     *
     * @param int $scaled   der Wert in Hundertsteln, Tausendsteln, …
     * @param int $decimals wie viele Nachkommastellen die Skala hat, 0 bis 18
     *
     * @throws InvalidArgumentException
     */
    public static function asDecimal(int $scaled, int $decimals): string
    {
        $scale = self::scaleOf($decimals);

        if (1 === $scale) {
            return (string) $scaled;
        }

        return ($scaled < 0 ? '-' : '')
            .self::digits(intdiv($scaled, $scale))
            .'.'
            .str_pad(self::digits($scaled % $scale), $decimals, '0', \STR_PAD_LEFT);
    }

    /**
     * Zehn hoch `$decimals`, als Ganzzahl.
     *
     * Ausgerechnet und nicht mit `10 **`: der Operator liefert ab neunzehn
     * Stellen ein Fliesskomma zurueck, und genau darum geht es hier nicht.
     *
     * @throws InvalidArgumentException
     */
    private static function scaleOf(int $decimals): int
    {
        if ($decimals < 0 || $decimals > self::MOST_DECIMALS) {
            throw new InvalidArgumentException('Eine Skala hat 0 bis '.self::MOST_DECIMALS.' Nachkommastellen.');
        }

        $scale = 1;

        for ($step = 0; $step < $decimals; ++$step) {
            $scale *= 10;
        }

        return $scale;
    }

    /** Die Ziffern ohne Vorzeichen — das steht schon davor. */
    private static function digits(int $part): string
    {
        return (string) ($part < 0 ? -$part : $part);
    }
}
