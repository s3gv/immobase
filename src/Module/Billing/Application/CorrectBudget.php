<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetCannotBeCorrected;
use App\Module\Billing\Domain\BudgetIterationIsTaken;
use App\Module\Billing\Domain\BudgetRepository;

/**
 * Eine berichtigte Fassung anlegen.
 *
 * Dieselben zwei Regeln wie beim Vermoegensbericht: ein Entwurf wird
 * bearbeitet und nicht berichtigt, und berichtigt wird die juengste Fassung.
 * Die neue traegt dieselbe Nummer, dieselbe Massnahme und dieselben
 * Positionen — falsch war eine einzelne Angabe.
 *
 * **Die Zustimmung kommt nicht mit.** Ueber eine berichtigte Fassung wird neu
 * beschlossen, und wer beim ersten Mal zugestimmt hat, muss es beim zweiten
 * nicht wieder tun. Die alte Zustimmung stehenzulassen hiesse, eine Stimme zu
 * behaupten, die niemand abgegeben hat.
 */
final readonly class CorrectBudget
{
    public function __construct(private BudgetRepository $budgets)
    {
    }

    /**
     * @throws BudgetCannotBeCorrected
     * @throws BudgetIterationIsTaken
     */
    public function of(Budget $original): Budget
    {
        if ($original->stage()->isOpen()) {
            throw BudgetCannotBeCorrected::itIsStillADraft();
        }

        if (!$this->isTheLatest($original)) {
            throw BudgetCannotBeCorrected::itIsNotTheLatestVersion();
        }

        $correction = self::copyOf($original);
        $this->budgets->save($correction);

        return $correction;
    }

    /** Eine schon angefangene Berichtigung — dann fuehrt der Knopf dorthin. */
    public function openFor(int $number): ?Budget
    {
        foreach ($this->budgets->iterationsOf($number) as $iteration) {
            if ($iteration->stage()->isOpen()) {
                return $iteration;
            }
        }

        return null;
    }

    public function canBeCorrected(Budget $budget): bool
    {
        return !$budget->stage()->isOpen() && $this->isTheLatest($budget);
    }

    /** Dieselbe Massnahme, derselbe Schluessel, dieselbe Finanzierung. */
    private static function copyOf(Budget $original): Budget
    {
        $correction = new Budget(
            $original->edition()->number(),
            $original->propertyId(),
            $original->propertyNumber(),
            $original->measure(),
        );
        $correction->corrects($original);
        $correction->distributeBy(
            $original->key()->id(),
            $original->key()->label(),
            $original->key()->kind(),
        );
        $correction->fund($original->funding());

        foreach ($original->positions() as $position) {
            $position->copyInto($correction);
        }

        return $correction;
    }

    private function isTheLatest(Budget $budget): bool
    {
        foreach ($this->budgets->iterationsOf($budget->edition()->number()) as $iteration) {
            if ($iteration->edition()->iteration() > $budget->edition()->iteration()) {
                return false;
            }
        }

        return true;
    }
}
