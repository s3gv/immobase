<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Bank;

/**
 * Eine Glaeubiger-Identifikationsnummer fuer SEPA-Lastschriften.
 *
 * Wer Lastschriften einzieht, bekommt sie von der Bundesbank: `DE98ZZZ09999999999`
 * — Land, zwei Pruefziffern, drei Stellen Geschaeftsbereich, dann die
 * eigentliche Kennung. Eine E-Rechnung ueber eine Lastschrift nennt sie
 * (BT-90), damit der Zahlende den Einzug zuordnen kann.
 *
 * Geprueft wird wie bei der IBAN nach mod 97 — nur ohne den
 * Geschaeftsbereich, der fuer die Pruefziffer nicht zaehlt.
 */
final readonly class CreditorId
{
    /** Land, Pruefziffern, Geschaeftsbereich, Kennung. */
    private const string SHAPE = '/^([A-Z]{2})(\d{2})[A-Z0-9]{3}([A-Z0-9]{1,28})$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws NotACreditorId
     */
    public static function fromString(string $value): self
    {
        $normalised = strtoupper(str_replace(' ', '', trim($value)));

        if (1 !== preg_match(self::SHAPE, $normalised, $parts) || 1 !== self::checksumOf($parts[3].$parts[1].$parts[2])) {
            throw new NotACreditorId();
        }

        return new self($normalised);
    }

    /** Leere Eingabe heisst „nicht angegeben" und ist kein Fehler. */
    public static function orNull(string $value): ?self
    {
        return '' === trim($value) ? null : self::fromString($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    /** Buchstaben zu Zahlen (A = 10 … Z = 35), der Rest bei Teilung durch 97. */
    private static function checksumOf(string $rearranged): int
    {
        $digits = '';

        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_alpha($character) ? (string) (\ord($character) - \ord('A') + 10) : $character;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
