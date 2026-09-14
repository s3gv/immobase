<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Change;

/**
 * Ein Feld, das jemand von aussen aendern lassen darf.
 *
 * Beschriftung als Uebersetzungsschluessel und nicht als fertiger Text: das
 * besitzende Modul soll keinen Uebersetzer brauchen, um zu sagen, welche
 * Felder es freigibt, und dieselbe Beschriftung steht ohnehin schon in
 * seinen eigenen Formularen.
 *
 * Der heutige Wert kommt als Zeichenkette. Eine Flaeche ist eine
 * Dezimalzahl und ein Datum ein Datum — aber ein Vorschlag ist ein
 * Schriftstueck, und was darin steht, wird beim Uebernehmen von dem Modul
 * gelesen, das die Regeln kennt.
 */
final readonly class ChangeableField
{
    /**
     * @param list<array{value: string, labelKey: string}> $choices nur bei FieldKind::Choice
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public FieldKind $kind,
        public string $value,
        public array $choices = [],
    ) {
    }
}
