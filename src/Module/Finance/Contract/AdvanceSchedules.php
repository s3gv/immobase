<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Beschlossene Vorschuesse in die Hausgeldstaffel schreiben.
 *
 * Die Gegenrichtung zu {@see CostDirectory}: dort holt die Abrechnung, was
 * ein Jahr gekostet hat, hier legt der Wirtschaftsplan ab, was die Eigentuemer
 * ab dem naechsten Jahr zahlen.
 *
 * **Es gibt nur eine Wahrheit ueber den Betrag.** Solange der Plan nur einen
 * Vorschlag machte und die Staffel daneben von Hand gepflegt wuerde, sagte der
 * Beschluss das eine und die Buchhaltung das andere — und die Jahresabrechnung
 * rechnete gegen die falsche Zahl. Darum schreibt die Freigabe.
 */
interface AdvanceSchedules
{
    /**
     * Je Einheit eine Stufe zum ersten Faelligkeitstag.
     *
     * Gibt es zu diesem Tag schon eine Stufe, wird sie ueberschrieben: eine
     * Korrektur beschliesst denselben Tag noch einmal, und zwei Stufen zum
     * selben Tag waeren zwei Antworten auf die Frage, was zu zahlen ist.
     *
     * @param list<PlannedAdvance> $advances
     */
    public function decided(array $advances): void;
}
