<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetDocument;
use App\Module\Billing\Domain\ProposedBudget;

/**
 * Aus einem Vorschlag werden Schreiben.
 *
 * Eingefroren wird genau das, was in der Vorschau stand: Name, Anschrift und
 * die vier Betraege. Aendert sich spaeter ein Miteigentumsanteil, steht auf
 * dem zugestellten Blatt weiter der alte Anteil — beschlossen wurde er.
 */
final class FreezeBudget
{
    private function __construct()
    {
    }

    public static function of(Budget $budget, ProposedBudget $proposal): void
    {
        $budget->clearDocuments();

        foreach ($proposal->shares as $share) {
            new BudgetDocument(
                $budget,
                $share->unitId,
                $share->unitNumber,
                $share->unitLabel,
                $share->recipientLabel,
                $share->recipientAddress,
                $share->share,
                $share->levy,
                $share->saving,
                $share->loanPayment,
            );
        }
    }
}
