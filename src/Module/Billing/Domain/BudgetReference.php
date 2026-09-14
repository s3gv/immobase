<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Die Nummer, unter der ein Budgetplan angesprochen wird.
 *
 * `BU-<Objektnummer>/<Einheitennummer>-<Jahr>-<Budgetnummer>-<Fassung>` —
 * dieselbe Form wie bei Abrechnung, Wirtschaftsplan und Vermoegensbericht, nur
 * mit einem anderen Kuerzel. Sie steht auch auf der Sonderumlage: wer eine
 * Zahlung nicht zuordnen kann, findet ueber sie den Beschluss.
 */
final class BudgetReference
{
    private function __construct()
    {
    }

    /**
     * Die Nummer der **Massnahme** — ohne Einheit und ohne Fassung.
     *
     * Auf dem Schreiben steht beides, denn ein Schreiben gehoert zu genau
     * einer Einheit und einer Fassung. Die Massnahme selbst ist beides nicht:
     *
     * * **Ohne Fassung**, weil eine Berichtigung dieselbe Sonderumlage noch
     *   einmal beschliesst und dieselbe Forderung vorfinden soll — mit allem,
     *   was jemand ueber sie vermerkt hat. Stuende die Fassung darin,
     *   entstuende bei jeder Berichtigung eine zweite Zahlung neben der ersten.
     * * **Ohne Einheit**, weil die Einheit an der Zahlung ohnehin steht. Und
     *   weil dieselbe Nummer an der Kostenposition steht, die die Massnahme
     *   bezahlt — die gehoert keiner Einheit.
     *
     * Damit ist sie an beiden Enden dieselbe: das Geld, das hereinkommt, und
     * die Rechnung, die hinausgeht, tragen die Nummer desselben Beschlusses.
     */
    public static function forTheMeasure(Budget $budget): string
    {
        return \sprintf(
            'BU-%d-%d-%d',
            $budget->propertyNumber(),
            $budget->measure()->firstYear(),
            $budget->edition()->number(),
        );
    }

    public static function of(Budget $budget, int $unitNumber): Reference
    {
        return new Reference(
            'BU',
            $budget->propertyNumber(),
            $unitNumber,
            $budget->measure()->firstYear(),
            $budget->edition()->number(),
            $budget->edition()->iteration(),
        );
    }
}
