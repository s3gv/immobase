<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Change;

/**
 * Wie ein Feld einzugeben ist.
 *
 * Nicht, was es bedeutet — das weiss nur das besitzende Modul. Hier steht
 * gerade so viel, dass die Oberflaeche das richtige Eingabefeld zeichnen
 * kann: ein Datum bekommt einen Kalender, eine Auswahl eine Liste, und
 * mehrzeilige Angaben ein Feld mit Platz.
 */
enum FieldKind: string
{
    case Text = 'text';

    /** Eine Angabe je Zeile — E-Mail-Adressen, Telefonnummern. */
    case Lines = 'lines';

    case Date = 'date';
    case Decimal = 'decimal';
    case Choice = 'choice';
}
