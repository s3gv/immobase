<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use DateTimeImmutable;

/**
 * Was an Vorauszahlungen faellig war und was davon kam.
 *
 * Beide Sorten aus einer Hand: das Hausgeld gehoert den Finanzen, die
 * Nebenkosten stehen im Mietvertrag — die Zahlung darauf ist beide Male
 * dieselbe Frage und liegt darum an einer Stelle.
 */
interface AdvanceDirectory
{
    /**
     * @param list<string> $unitIds
     *
     * @return list<PaymentRecord> zeitlich sortiert
     */
    public function paymentsFor(array $unitIds, int $fiscalYear): array;

    /**
     * Jede Zahlung, die an diesem Tag ueberfaellig war.
     *
     * Ohne Einheitenliste und ohne Jahr: das Mahnwesen fragt nicht nach einem
     * Objekt, sondern danach, wo ueberhaupt etwas fehlt — und ein Rueckstand
     * aus dem Vorjahr ist heute immer noch einer.
     *
     * Ueberfaellig heisst: faellig gewesen und nicht vollstaendig angekommen.
     * Eine Teilzahlung steht mit ihrem Rest darin, denn ueber den Rest wird
     * gemahnt.
     *
     * **Der Faelligkeitstag selbst zaehlt nicht.** An ihm laeuft die Frist
     * noch den ganzen Tag; ueberfaellig ist eine Zahlung erst am Tag danach
     * (§ 286 Abs. 2 Nr. 1, § 187 Abs. 1 BGB).
     *
     * @return list<PaymentRecord> die aelteste Faelligkeit zuerst
     */
    public function overdueOn(DateTimeImmutable $day): array;
}
