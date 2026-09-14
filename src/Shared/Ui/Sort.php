<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Ui;

/**
 * Wonach eine Uebersicht sortiert ist — und in welche Richtung.
 *
 * Wie `Page` kommt beides aus der Adresszeile und ist damit Eingabe. Ein Feld,
 * das es nicht gibt, ist deshalb kein Fehler, sondern eine Angabe, die es so
 * nicht gibt: sie faellt auf die Voreinstellung zurueck. Alles andere hiesse,
 * eine Uebersicht mit einer Fehlerseite zu beantworten, weil jemand an der
 * Adresse gedreht hat.
 *
 * Die erlaubten Felder stehen im Aufrufer und nicht hier: sie sind
 * Spaltennamen und gehen so in die Abfrage. Was nicht in der Liste steht, kommt
 * nicht durch — das ist die ganze Absicherung, und sie muss an der Stelle
 * sein, die die Liste kennt.
 */
final readonly class Sort
{
    public const string ASCENDING = 'auf';
    public const string DESCENDING = 'ab';

    private function __construct(
        public string $field,
        public string $direction,
    ) {
    }

    /** Die Voreinstellung einer Uebersicht. */
    public static function by(string $field, string $direction = self::ASCENDING): self
    {
        return new self($field, self::DESCENDING === $direction ? self::DESCENDING : self::ASCENDING);
    }

    /**
     * Was in der Adresszeile stand — geprueft gegen das, was es gibt.
     *
     * @param list<string> $allowed
     */
    public static function of(string $field, string $direction, array $allowed, self $fallback): self
    {
        return \in_array($field, $allowed, true) ? self::by($field, $direction) : $fallback;
    }

    public function isOn(string $field): bool
    {
        return $field === $this->field;
    }

    public function isDescending(): bool
    {
        return self::DESCENDING === $this->direction;
    }

    /**
     * Die Richtung, die ein Klick auf diese Spalte ergibt.
     *
     * Eine fremde Spalte beginnt aufsteigend, die eigene dreht um. Alles
     * andere waere ein Knopf, dessen Wirkung man raten muss.
     */
    public function nextDirectionFor(string $field): string
    {
        if (!$this->isOn($field)) {
            return self::ASCENDING;
        }

        return $this->isDescending() ? self::ASCENDING : self::DESCENDING;
    }

    /** Was Hilfsmittel vorlesen: aufsteigend, absteigend oder gar nicht. */
    public function ariaFor(string $field): string
    {
        if (!$this->isOn($field)) {
            return 'none';
        }

        return $this->isDescending() ? 'descending' : 'ascending';
    }

    /** Fuer die Abfrage: ASC oder DESC. */
    public function sql(): string
    {
        return $this->isDescending() ? 'DESC' : 'ASC';
    }
}
