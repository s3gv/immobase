<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Search;

/**
 * Ein Treffer der zentralen Suche.
 *
 * Fertig zum Anzeigen: die Bezeichnung, eine Zeile darunter, die Nummer und
 * ein Weg dorthin. **Die Adresse kommt vom Modul:** wer die Route kennt,
 * baut sie, und die Suche kennt keine fremden Routen.
 *
 * Welcher Art der Treffer ist, steht nicht hier, sondern an seiner Quelle:
 * eine Quelle liefert eine Art, und damit steht es einmal statt an jedem
 * Treffer.
 */
final readonly class SearchHit
{
    public function __construct(
        /** Die Bezeichnung — der Name, unter dem jemand den Datensatz sucht. */
        public string $title,
        /**
         * Die Zeile darunter: was zwei gleichnamige Treffer unterscheidet.
         *
         * Bei einer Einheit das Objekt, bei einem Kontakt die Anschrift. Leer
         * ist erlaubt; erfunden werden soll hier nichts.
         */
        public string $subtitle,
        /** Die Nummer, unter der er auch gefunden werden kann. */
        public string $reference,
        public string $url,
    ) {
    }
}
