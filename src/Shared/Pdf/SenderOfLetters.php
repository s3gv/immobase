<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Woher der Briefkopf seine Absenderangaben bekommt.
 *
 * Der Anschluss steht hier, die Umsetzung im Einstellungen-Modul: Shared darf
 * kein Fachmodul kennen, und ein Briefkopf, der sich die Anschrift selbst aus
 * den Einstellungen holte, waere genau das.
 *
 * Gefragt wird bei jedem Brief neu. Das ist der eine bewusste Bruch mit der
 * Byte-Gleichheit der Schreiben: wer seine Anschrift aendert, will sie auf dem
 * naechsten Ausdruck sehen. Die **Berechnung** ist eingefroren, die
 * **Absenderangaben** sind es nicht.
 */
interface SenderOfLetters
{
    public function sender(): Sender;
}
