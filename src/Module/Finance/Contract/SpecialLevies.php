<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Beschlossene Sonderumlagen als faellige Zahlungen anlegen.
 *
 * Der zweite Schreibweg neben {@see AdvanceSchedules}, und er sieht anders
 * aus: eine Staffel sagt, was ab einem Tag *wiederkehrend* gilt; eine
 * Sonderumlage ist ein einzelner Termin mit einem einzelnen Betrag.
 *
 * **Damit sie ueberhaupt jemand einfordern kann.** Eine Sonderumlage, die nur
 * auf einem Blatt stuende, waere fuer die Anwendung nicht vorhanden: sie
 * erschiene weder auf der Zahlungsseite der Einheit noch als Forderung im
 * Vermoegensbericht. Beschlossen ist sie aber, und was beschlossen ist,
 * schuldet jemand.
 */
interface SpecialLevies
{
    /**
     * Je Einheit und Faelligkeit eine Zahlung.
     *
     * Gibt es zu diesem Tag schon eine Sonderumlage derselben Einheit, wird
     * sie ueberschrieben: eine Korrektur beschliesst denselben Termin noch
     * einmal, und zwei Zahlungen zum selben Tag waeren zwei Antworten auf die
     * Frage, was zu zahlen ist.
     *
     * Mitgegeben wird auch, was die Fassung davor vorsah: was dort stand und
     * hier nicht mehr, wird zurueckgenommen. Beides in einem Zug, weil es ein
     * Vorgang ist — stuende dazwischen ein Fehlschlag, schuldete die Einheit
     * die alte und die neue Rate.
     *
     * @param list<PlannedLevy>   $levies
     * @param list<WithdrawnLevy> $insteadOf was die berichtigte Fassung ersetzt
     */
    public function decided(array $levies, array $insteadOf): void;
}
