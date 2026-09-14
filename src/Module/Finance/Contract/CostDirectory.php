<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Was ein Objekt in einem Wirtschaftsjahr gekostet hat.
 *
 * Die Flaeche, ueber die die Abrechnung an die Kosten kommt. Sie liefert und
 * rechnet nicht: die Verteilung gehoert dem Modul, das abrechnet, damit eine
 * Zahl an genau einer Stelle entsteht.
 */
interface CostDirectory
{
    /**
     * Alle erfassten Jahreswerte des Objekts, nach Kostenart sortiert.
     *
     * Positionen ohne Wert fuer dieses Jahr fehlen — sie haben nichts
     * gekostet, und eine Null waere eine Behauptung.
     *
     * @return list<CostRecord>
     */
    public function forYear(string $propertyId, int $fiscalYear): array;

    /**
     * Was fuer eine beschlossene Massnahme ausgegeben wurde.
     *
     * Die Nummer des Beschlusses steht an der Kostenposition, seit jemand sie
     * dort gewaehlt hat. Ohne sie faende der Budgetplan nie heraus, ob dem
     * Geld, das er eingesammelt hat, auch Rechnungen gegenueberstehen.
     *
     * @return list<MeasureCost> nach Jahr, das aelteste zuerst
     */
    public function forMeasure(string $reference): array;
}
