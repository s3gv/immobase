<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\LatestEditions;
use App\Shared\Money\Money;

/**
 * Was beschlossene Budgetplaene fuer ein Jahr an Zufuehrung verlangen.
 *
 * Die Bruecke zwischen Budgetplan und Wirtschaftsplan, und sie geht nur in
 * diese Richtung: der Budgetplan **schreibt keine Vorschuesse**. Ueber die
 * beschliesst die Versammlung im Wirtschaftsplan (§ 28 Abs. 1 WEG), und ihn
 * zu umgehen hiesse, am Beschluss vorbei ueber Hausgeld zu entscheiden.
 *
 * Was er stattdessen tut: die Ruecklagenzeile des betroffenen Jahres
 * **vorbelegen**. Wer ansparen beschlossen hat, findet die Zahl im
 * Wirtschaftsplan wieder und muss sie nicht aus einem anderen Beschluss
 * abtippen.
 *
 * Gezaehlt wird je Massnahme nur die juengste Fassung: eine berichtigte
 * ersetzt ihre Vorgaengerin, sie kommt nicht dazu.
 */
final readonly class SavedByBudgets
{
    public function __construct(private BudgetRepository $budgets)
    {
    }

    public function inYear(string $propertyId, int $year): Money
    {
        $sum = Money::zero();

        foreach (LatestEditions::of($this->budgets->decidedFor($propertyId)) as $budget) {
            $sum = $sum->plus(FundingSchedules::savingPerYear($budget->funding())[$year] ?? Money::zero());
        }

        return $sum;
    }
}
