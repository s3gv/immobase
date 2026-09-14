<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Search;

use App\Shared\Text\Trimmed;

/**
 * Was jemand in das Feld oben getippt hat — einmal aufbereitet.
 *
 * Einmal und nicht in jedem Modul: ein Modul, das eine IBAN anders
 * zusammenzieht als das naechste, findet dieselbe Eingabe einmal und einmal
 * nicht. Wer sucht, merkt davon nur, dass die Suche unzuverlaessig ist.
 *
 * Steht in Shared, weil jedes Fachmodul denselben Text bekommt.
 */
final readonly class SearchTerm
{
    /**
     * Unter zwei Zeichen wird nicht gesucht.
     *
     * Eine Suche nach „a" ist keine Frage, sondern ein Tastendruck — und sie
     * laedt aus jedem Modul das halbe Haus.
     */
    public const int LEAST = 2;

    private function __construct(
        /** Wie getippt, nur ohne aeussere Leerzeichen — fuer die Anzeige. */
        public string $raw,
        /** Kleingeschrieben und ohne Platzhalter — fuer den Vergleich. */
        public string $text,
    ) {
    }

    /**
     * Nichts, zu kurz, oder ein Suchbegriff.
     *
     * Zu kurz ist kein Fehler: es ist der Zustand zwischen dem ersten und dem
     * zweiten Tastendruck.
     */
    public static function orNull(?string $input): ?self
    {
        $trimmed = Trimmed::orNull($input);

        if (null === $trimmed) {
            return null;
        }

        $text = self::withoutWildcards(mb_strtolower($trimmed));

        return mb_strlen($text) < self::LEAST ? null : new self($trimmed, $text);
    }

    /**
     * Zum Vergleich mit einer Nummer — und nur dann.
     *
     * „100" soll die Nummer 100 finden und nicht jede, in der eine 100
     * vorkommt. Wer nach Text sucht, sucht mit {@see contains()}.
     */
    public function isNumber(): bool
    {
        return '' !== $this->text && ctype_digit($this->text);
    }

    public function number(): int
    {
        return (int) $this->text;
    }

    /**
     * Das Muster fuer ein LIKE.
     *
     * Die Platzhalter sind schon draussen: sie werden beim Aufbereiten
     * entfernt, nicht hier. Sonst gaebe es zwei Stellen, an denen daran
     * gedacht werden muss, und die zweite waere die vergessene.
     */
    public function contains(): string
    {
        return '%'.$this->text.'%';
    }

    /**
     * Dieselbe Eingabe ohne Leerzeichen und in Grossbuchstaben.
     *
     * Fuer die IBAN: gespeichert steht sie am Stueck, getippt wird sie in
     * Vierergruppen. Ohne das findet „DE02 1203" nichts, obwohl es dasteht.
     */
    public function compact(): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $this->text));
    }

    /**
     * Prozent und Unterstrich haben in einer Suche nach Namen, Nummern und
     * Kontonummern nichts verloren — in einem LIKE sind sie Platzhalter.
     *
     * Entfernt statt maskiert: eine Maskierung braucht an jeder Abfrage eine
     * ESCAPE-Angabe, und die wird irgendwo vergessen. Was hier wegfaellt,
     * fehlt niemandem.
     */
    private static function withoutWildcards(string $text): string
    {
        return str_replace(['%', '_', '\\'], '', $text);
    }
}
