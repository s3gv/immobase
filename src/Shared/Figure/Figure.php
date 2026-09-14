<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Figure;

/**
 * Eine Zahl auf der Uebersicht.
 *
 * **Der Wert kommt fertig geschrieben.** Nur das Modul weiss, ob seine Zahl
 * ein Betrag ist, eine Anzahl, eine Dauer oder ein Anteil — und wuerde
 * `Figure` das wissen muessen, muesste es `Shared\Money` kennen, und die
 * naechste Zahl braechte eine Einheit mit, die es wieder nicht kennt.
 * Geschrieben wird mit denselben Werkzeugen wie ueberall.
 *
 * **Nur Zahlen, zu denen es eine Handlung gibt.** „Angelegte Kontakte
 * gesamt" ist Ballast: niemand tut etwas anders, weil dort 412 steht.
 */
final readonly class Figure
{
    public function __construct(
        public FigureGroup $group,
        public string $labelKey,
        /** Fertig in der Sprache der Anfrage geschrieben. */
        public string $value,
        /** Der Ton der Kachel: `neutral`, `success`, `warning`, `danger`. */
        public string $tone = 'neutral',
        /** Wohin die Zahl fuehrt — dorthin, wo sie erklaert wird. */
        public string $url = '',
        /**
         * Eine fertige Beschriftung statt eines Schluessels.
         *
         * Fuer Plugins: der Core kennt den Wortlaut eines fremden Plugins
         * nicht, und ein Drittanbieter kann unsere Uebersetzungsdateien nicht
         * ergaenzen. Module lassen das Feld leer und benennen ihren Schluessel.
         */
        public string $label = '',
    ) {
    }
}
