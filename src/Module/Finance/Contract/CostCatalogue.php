<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Die gepflegten Listen, aus denen eine Planung ihre Zeilen nimmt.
 *
 * {@see CostDirectory} liefert, was ein Jahr gekostet **hat**. Wer plant,
 * braucht daneben, was es ueberhaupt gibt: eine Kostenart, die es im Vorjahr
 * nicht gab, gehoert trotzdem in den Plan, sobald der Vertrag dafuer
 * unterschrieben ist.
 */
interface CostCatalogue
{
    /**
     * Alle Kostenarten, in der gewohnten Reihenfolge der BetrKV.
     *
     * @return list<CostKindBrief>
     */
    public function kinds(): array;

    /**
     * Die Systemschluessel und die eigenen dieses Objekts.
     *
     * @return list<DistributionKeyBrief>
     */
    public function keysFor(string $propertyId): array;

    /**
     * Die beiden Kostenarten, unter denen ein Darlehen im Plan steht.
     *
     * Eigens gefragt und nicht aus {@see kinds()} herausgesucht: welche der
     * Arten die Darlehenszinsen sind, weiss das Modul, dem sie gehoeren. Wer
     * anderswo nach einem Namen suchte, faende ihn in der naechsten
     * Installation anders geschrieben.
     */
    public function loanKinds(): LoanCostKinds;
}
