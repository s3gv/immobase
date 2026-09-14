<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Text\Trimmed;

/**
 * Eine einzelne Anschrift — eine Hausanschrift oder ein Postfach.
 *
 * Beides nebeneinander ist erlaubt und kommt haeufig vor: Post geht ans
 * Postfach, der Handwerker faehrt zur Hausanschrift. Welche gilt, wenn nur
 * eine gemeint sein kann, entscheidet die Reihenfolge in Addresses.
 */
final readonly class PostalAddress
{
    private function __construct(
        public AddressKind $kind,
        public string $line,
        public string $postalCode,
        public string $city,
        /**
         * Die Zusatzzeile des Anschriftfeldes — „c/o", ein Gebaeude, ein
         * Stockwerk, bei einer Firma der Ansprechpartner.
         *
         * Freiwillig, und meistens leer. Sie steht nach DIN 5008 zwischen
         * Namen und Strasse; wer sie braucht, braucht sie dringend — ohne
         * „c/o" kommt der Brief zurueck.
         */
        public string $addition = '',
    ) {
    }

    public static function of(
        AddressKind $kind,
        string $line,
        string $postalCode,
        string $city,
        string $addition = '',
    ): self {
        return new self(
            $kind,
            Trimmed::required($line, AddressKind::PoBox === $kind ? 'Postfach' : 'Straße und Hausnummer'),
            Trimmed::required($postalCode, 'Postleitzahl'),
            Trimmed::required($city, 'Ort'),
            Trimmed::orNull($addition) ?? '',
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            AddressKind::tryFrom(self::text($data, 'kind')) ?? AddressKind::Street,
            self::text($data, 'line'),
            self::text($data, 'postalCode'),
            self::text($data, 'city'),
            self::text($data, 'addition'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        // Der Zusatz steht nur da, wenn es einen gibt: sonst traegt jede
        // Anschrift in der Datenbank ein leeres Feld mit, das nie jemand
        // gefuellt hat.
        return array_filter([
            'kind' => $this->kind->value,
            'line' => $this->line,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'addition' => $this->addition,
        ], static fn (string $value): bool => '' !== $value);
    }

    public function isPoBox(): bool
    {
        return AddressKind::PoBox === $this->kind;
    }

    /**
     * Einzeilig, fuer Listen und Auswahlfelder.
     *
     * **Ohne den Zusatz.** In einer Zeile, die eine Anschrift nur kenntlich
     * macht, hilft „c/o Hausverwaltung Nord" niemandem beim Wiedererkennen —
     * auf dem Kuvert dagegen entscheidet es darueber, ob der Brief ankommt.
     * Dort setzt ihn {@see \App\Shared\Pdf\PostalLines}.
     */
    public function oneLine(): string
    {
        return \sprintf('%s, %s %s', $this->line, $this->postalCode, $this->city);
    }

    /**
     * Einzeilig **mit** dem Zusatz.
     *
     * Fuer die eine Stelle, an der jemand seine eigenen Daten liest: was
     * ueber ihn gespeichert ist, soll er auch sehen — sonst schlaegt er eine
     * Zeile vor, die er nirgends wiederfindet.
     */
    public function readable(): string
    {
        return '' === $this->addition ? $this->oneLine() : $this->addition.', '.$this->oneLine();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
