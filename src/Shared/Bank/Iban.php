<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Bank;

/**
 * Eine IBAN als Wertobjekt.
 *
 * Normalisiert ohne Leerzeichen und in Grossbuchstaben: getippt wird sie in
 * Vierergruppen, gespeichert am Stueck, und derselbe Vertrag soll nicht
 * zweimal danebenstehen, nur weil einmal Leerzeichen drin waren.
 *
 * Geprueft wird die Pruefziffer nach ISO 13616 (mod 97). Das faengt den
 * Zahlendreher ab, und der ist der haeufige Fehler — eine Ueberweisung auf
 * eine formal gueltige, aber falsche IBAN merkt niemand, bis sie
 * zurueckkommt.
 *
 * Was hier nicht geprueft wird: ob die Laenge zum Land passt. Dafuer
 * braeuchte es eine Tabelle aller Laender, die gepflegt werden will, und
 * mod 97 faengt fast dasselbe ab. Liegt in Shared, weil die Verwaltung und
 * jedes Objekt dieselbe Pruefung brauchen — wie bei {@see \App\Shared\Contact\Email}.
 */
final readonly class Iban
{
    /** Zwei Buchstaben Land, zwei Pruefziffern, dann bis zu 30 Stellen. */
    private const string SHAPE = '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws NotAnIban
     */
    public static function fromString(string $value): self
    {
        $normalised = strtoupper(str_replace(' ', '', trim($value)));

        if (1 !== preg_match(self::SHAPE, $normalised) || 1 !== self::checksumOf($normalised)) {
            throw new NotAnIban();
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

    /** In Vierergruppen — so steht sie auf jedem Kontoauszug. */
    public function formatted(): string
    {
        return implode(' ', str_split($this->value, 4));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Die Pruefsumme nach ISO 13616.
     *
     * Die ersten vier Stellen wandern ans Ende, Buchstaben werden zu Zahlen
     * (A = 10 … Z = 35), und der Rest muss bei Teilung durch 97 eins sein.
     *
     * Gerechnet wird stueckweise: die Zahl hat bis zu 38 Stellen und passt
     * in keinen Integer.
     */
    private static function checksumOf(string $iban): int
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $digits = '';

        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_alpha($character)
                ? (string) (\ord($character) - \ord('A') + 10)
                : $character;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
