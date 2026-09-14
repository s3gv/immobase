<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetPosition;
use App\Module\Billing\Domain\BudgetRepository;
use App\Shared\Money\Money;

/**
 * Die Positionen eines Budgetplans pflegen.
 *
 * Jede Zeile wird ueber ihre eigene Kennung angesprochen und nicht ueber ihre
 * Stelle in der Liste: wer eine Zeile entfernt und dann speichert, verschoebe
 * sonst alle darunter.
 */
final readonly class BudgetPositions
{
    public function __construct(private BudgetRepository $budgets)
    {
    }

    /**
     * @param array<string, array{label: string, year: int, amount: Money, note: string}> $rows
     */
    public function keep(Budget $budget, array $rows): void
    {
        foreach ($budget->positions() as $position) {
            $row = $rows[$position->id()] ?? null;

            if (null !== $row) {
                $position->describe($row['label'], $row['year'], $row['amount'], $row['note']);
            }
        }

        $this->budgets->save($budget);
    }

    /** Eine leere Zeile ans Ende — im Jahr der letzten. */
    public function add(Budget $budget): void
    {
        $last = null;

        foreach ($budget->positions() as $position) {
            $last = $position;
        }

        new BudgetPosition(
            $budget,
            null === $last ? 1 : $last->ordering() + 1,
            $last?->year() ?? $budget->measure()->firstYear(),
        );
        $this->budgets->save($budget);
    }

    public function remove(Budget $budget, string $positionId): void
    {
        foreach ($budget->positions() as $position) {
            if ($position->id() === $positionId) {
                $budget->drop($position);
            }
        }

        $this->budgets->save($budget);
    }
}
