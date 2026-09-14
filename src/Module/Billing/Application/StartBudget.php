<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetPosition;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\Funding;
use App\Module\Billing\Domain\LevyPurpose;
use App\Module\Billing\Domain\Measure;
use App\Module\Billing\Domain\MeasureKind;
use App\Module\Finance\Contract\CostCatalogue;
use App\Module\Finance\Contract\DistributionKeyBrief;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;

/**
 * Einen Budgetplan anlegen — und gleich vorbelegen.
 *
 * Zwei Vorbelegungen, und beide sparen einen Griff, der sonst jedes Mal
 * derselbe waere: eine erste, leere Position, und der Verteilerschluessel nach
 * **Miteigentumsanteilen**. Das ist der gesetzliche Schluessel (§ 16 Abs. 2
 * WEG), und wer abweichend verteilen will, waehlt um — das duerfen die
 * Eigentuemer seit 2020 mit einfacher Mehrheit beschliessen (§ 16 Abs. 2
 * Satz 2).
 *
 * **Nur WEG-Objekte.** Ueber eine Massnahme am Gemeinschaftseigentum
 * beschliesst eine Gemeinschaft; ohne sie gibt es niemanden, der beschliesst.
 */
final readonly class StartBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private PropertyDirectory $properties,
        private CostCatalogue $catalogue,
    ) {
    }

    /**
     * @return Budget|null null, wenn Objekt oder Jahr nicht taugen
     */
    public function forProperty(?int $propertyNumber, ?int $firstYear, string $label, ?string $kind): ?Budget
    {
        $property = null === $propertyNumber ? null : $this->withNumber($propertyNumber);

        if (null === $property || null === $firstYear || !$property->managesWeg) {
            return null;
        }

        $budget = new Budget(
            $this->budgets->nextNumber(),
            $property->id,
            $property->number,
            Measure::of($label, MeasureKind::tryFrom($kind ?? '') ?? MeasureKind::Maintenance, $firstYear),
        );
        $budget->fund(Funding::none()->levyGoing(LevyPurpose::forA($budget->measure()->kind())));
        $this->chooseTheUsualKey($budget, $property->id);
        $this->budgets->save($budget);

        new BudgetPosition($budget, 1, $firstYear);
        $this->budgets->save($budget);

        return $budget;
    }

    /** Nach Miteigentumsanteilen — der gesetzliche Schluessel. */
    private function chooseTheUsualKey(Budget $budget, string $propertyId): void
    {
        $keys = $this->catalogue->keysFor($propertyId);

        foreach ($keys as $key) {
            if ('mea' === $key->kind) {
                $budget->distributeBy($key->id, $key->label, $key->kind);

                return;
            }
        }

        $first = $keys[0] ?? null;

        if ($first instanceof DistributionKeyBrief) {
            $budget->distributeBy($first->id, $first->label, $first->kind);
        }
    }

    private function withNumber(int $number): ?PropertyBrief
    {
        foreach ($this->properties->all() as $property) {
            if ($property->number === $number) {
                return $property;
            }
        }

        return null;
    }
}
