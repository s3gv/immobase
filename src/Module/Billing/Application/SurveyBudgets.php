<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetFilter;
use App\Module\Billing\Domain\BudgetNeed;
use App\Module\Billing\Domain\BudgetReference;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\LatestEditions;
use App\Module\Finance\Contract\CostDirectory;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;

/**
 * Was die beschlossenen Massnahmen zusammen kosten.
 *
 * Die Zahl, die eine Verwaltung sucht, wenn jemand fragt, was ansteht.
 *
 * **Je Massnahme eine Fassung.** Eine Berichtigung ersetzt die Fassung davor;
 * beide zu addieren hiesse, dasselbe Dach zweimal zu decken. Gezaehlt wird
 * die juengste beschlossene — eine offene Berichtigung aendert daran nichts,
 * denn bis sie beschlossen ist, gilt, was beschlossen ist.
 */
final readonly class SurveyBudgets
{
    public function __construct(
        private BudgetRepository $budgets,
        private CostDirectory $costs,
    ) {
    }

    /**
     * Was auf der Uebersicht ueber die Budgetplaene steht.
     *
     * Drei Fragen: was ist beschlossen, was wartet noch auf einen Beschluss —
     * und wofuer ist Geld beschlossen und das Jahr angefangen, ohne dass je
     * eine Rechnung dagegen gebucht wurde. Die dritte stellt niemand, und genau darum muss sie
     * beantwortet werden: eine Sonderumlage ist ein Vorschuss, und ein
     * Vorschuss ohne Rechnung ist Geld, das irgendwo liegt.
     *
     * @return array{planned: Money, drafts: int, open: list<Budget>, unspent: list<Budget>}
     */
    public function overview(): array
    {
        $decided = $this->latest();

        return [
            'planned' => self::sumOf($decided),
            'drafts' => $this->budgets->countMatching(BudgetFilter::draftsOnly()),
            'open' => array_values(array_filter(
                $this->all(),
                static fn (Budget $budget): bool => $budget->stage()->isOpen(),
            )),
            'unspent' => array_values(array_filter($decided, fn (Budget $budget): bool => $this->isOverdue($budget))),
        ];
    }

    public function planned(): Money
    {
        return self::sumOf($this->latest());
    }

    /**
     * Laeuft die Massnahme schon — und ist trotzdem nichts gebucht?
     *
     * Das Jahr muss angefangen haben. Eine Massnahme, die fuer 2028
     * beschlossen ist, hat 2026 selbstverstaendlich keine Rechnungen; sie
     * hier zu melden hiesse, eine Warnung auszugeben, die jeder wegsieht —
     * und dann auch die echten.
     *
     * Gefragt wird ueber die Beschlussnummer, die an beiden Enden steht: am
     * Beschluss und an der Kostenposition, die ihn nennt. Ohne zugeordnete
     * Position weiss niemand etwas, und genau das ist der Hinweis.
     */
    private function isOverdue(Budget $budget): bool
    {
        $thisYear = (int) (new DateTimeImmutable('today'))->format('Y');

        return $budget->measure()->firstYear() <= $thisYear
            && [] === $this->costs->forMeasure(BudgetReference::forTheMeasure($budget));
    }

    /** @param list<Budget> $budgets */
    private static function sumOf(array $budgets): Money
    {
        $planned = Money::zero();

        foreach ($budgets as $budget) {
            $planned = $planned->plus(BudgetNeed::of($budget));
        }

        return $planned;
    }

    /**
     * Je Massnahmennummer die juengste beschlossene Fassung.
     *
     * @return list<Budget>
     */
    private function latest(): array
    {
        return LatestEditions::of(array_values(array_filter(
            $this->all(),
            static fn (Budget $budget): bool => !$budget->stage()->isOpen(),
        )));
    }

    /**
     * Alle Budgetplaene — es sind wenige, und die Uebersicht braucht sie ganz.
     *
     * @return list<Budget>
     */
    private function all(): array
    {
        $all = $this->budgets->countMatching(BudgetFilter::none());

        return $this->budgets->matching(BudgetFilter::none(), Page::of(1, max(1, $all)));
    }
}
