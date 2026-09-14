<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Das Anschriftfeld eines Briefes, Zeile fuer Zeile.
 *
 * Nach DIN 5008 stehen dort hoechstens sechs Zeilen: der Name, darunter bis
 * zu zwei Zusatzzeilen, dann Strasse oder Postfach, zuletzt Postleitzahl und
 * Ort. **Jede auf ihrer eigenen Zeile** — „Lindenallee 8, 40233 Duesseldorf"
 * in einer Zeile ist eine Listendarstellung und kein Anschriftfeld. Ein
 * Fensterkuvert zeigt nur das Feld; was nicht hineinpasst, sieht niemand.
 *
 * Woraus die Zeilen entstehen, weiss dieses Wertobjekt nicht — dass bei einer
 * Firma der Ansprechpartner in die Zusatzzeile gehoert, ist Sache des
 * Stammdatenmoduls. Hier steht nur, wie viele Zeilen das Feld traegt und
 * dass keine davon leer ist.
 */
final readonly class PostalLines
{
    /**
     * Sechs Zeilen, so steht es in der Norm. Die siebte faellt beim
     * Empfaenger aus dem Fenster.
     */
    public const int MOST = 6;

    /**
     * @param list<string> $lines
     */
    private function __construct(public array $lines)
    {
    }

    /**
     * @param list<string> $rest Zusatz, Strasse, Postleitzahl und Ort
     */
    public static function of(string $name, array $rest): self
    {
        return self::from([$name, ...$rest]);
    }

    /**
     * Aus einem Block, wie er eingefroren in einem Schreiben steht.
     *
     * Aeltere Schreiben tragen dort noch die zweizeilige Form. Sie bleibt,
     * wie sie ist: was eingefroren wurde, aendert sich nicht, auch nicht zum
     * Besseren.
     */
    public static function fromText(string $text): self
    {
        $lines = preg_split('/\R/', $text);

        return self::from(false === $lines ? [] : $lines);
    }

    public function toString(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * Dieselbe Anschrift in einer Zeile.
     *
     * Fuer die Stellen, an denen sie **im Text** steht und nicht im
     * Anschriftfeld: eine Zeile im Gerichtsauszug, der Aussteller einer
     * Rechnung. Dort ist sie eine Angabe unter vielen, und drei Zeilen
     * brechen die Reihe.
     */
    public function inline(): string
    {
        return implode(', ', $this->lines);
    }

    /**
     * @param list<string> $lines
     */
    private static function from(array $lines): self
    {
        $kept = [];

        foreach ($lines as $line) {
            if ('' !== trim($line)) {
                $kept[] = trim($line);
            }
        }

        return new self(\array_slice($kept, 0, self::MOST));
    }
}
