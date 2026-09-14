<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

/**
 * Ein Verweis auf eine Einheit — mit Adresse, damit man hinspringen kann.
 *
 * Der Unterschied zu PartyLinkSource, die nur Uebersetzungsschluessel traegt:
 * dort geht es allein darum, ob geloescht werden darf. Hier soll auf der
 * Einheitenseite ein Link stehen, und die Adresse kennt nur das Modul, das
 * den Verweis haelt.
 *
 * `countKey` sagt, wie mehrere davon heissen — „4 Mietverhaeltnisse". Die
 * Abwicklung zaehlt damit, was sie mitnimmt, ohne zu wissen, was sie zaehlt.
 * Leer heisst: zaehlt nicht mit. Was ohnehin schon beendet ist, wird von
 * einer Abwicklung nicht noch einmal beendet — und darf in der Vorschau nicht
 * mitgezaehlt werden, sonst nennt sie andere Zahlen als die Wirklichkeit.
 */
final readonly class UnitLink
{
    public function __construct(
        public string $labelKey,
        public string $text,
        public string $url,
        public string $countKey,
    ) {
    }
}
