<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetReference;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\LatestEditions;
use App\Module\Finance\Contract\DecidedMeasure;
use App\Module\Finance\Contract\DecidedMeasures;

/**
 * Die beschlossenen Massnahmen eines Objekts, fuer die Finanzen.
 *
 * Damit eine Rechnung sagen kann, wofuer sie da ist: wer eine Kostenposition
 * anlegt, waehlt die Massnahme aus dieser Liste. Je Vorgang die juengste
 * Fassung — eine berichtigte ersetzt ihre Vorgaengerin, und zwei Zeilen
 * derselben Massnahme in einer Auswahlliste waeren eine Falle.
 */
final readonly class SurveyDecidedMeasures implements DecidedMeasures
{
    public function __construct(private BudgetRepository $budgets)
    {
    }

    public function forProperty(string $propertyId): array
    {
        $measures = array_map(
            static fn (Budget $budget): DecidedMeasure => new DecidedMeasure(
                BudgetReference::forTheMeasure($budget),
                $budget->measure()->label(),
                $budget->measure()->firstYear(),
            ),
            LatestEditions::of($this->budgets->decidedFor($propertyId)),
        );

        usort(
            $measures,
            static fn (DecidedMeasure $one, DecidedMeasure $other): int => [$other->firstYear, $other->reference]
                <=> [$one->firstYear, $one->reference],
        );

        return $measures;
    }
}
