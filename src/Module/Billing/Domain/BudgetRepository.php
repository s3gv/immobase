<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Ui\Page;

interface BudgetRepository
{
    /**
     * @throws BudgetIterationIsTaken wenn dieselbe Fassung schon existiert
     */
    public function save(Budget $budget): void;

    /** Nur Entwuerfe — auf einem beschlossenen stehen Forderungen. */
    public function remove(Budget $budget): void;

    /**
     * Alles oder nichts.
     *
     * Die Freigabe greift ueber Modulgrenzen durch: der Plan wird beschlossen,
     * und je Einheit entsteht eine faellige Sonderumlage. Ein beschlossener
     * Plan, dessen Umlage nur halb geschrieben ist, waere schlimmer als der
     * Zustand vorher — der Status sperrt einen zweiten Versuch.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;

    public function byId(string $id): ?Budget;

    /** Die naechste sichtbare Nummer, aus der Sequenz. */
    public function nextNumber(): int;

    public function countMatching(BudgetFilter $filter): int;

    /**
     * @return list<Budget> die neuesten zuerst
     */
    public function matching(BudgetFilter $filter, Page $page): array;

    /**
     * Die Jahre, in denen Massnahmen beginnen — die juengsten zuerst.
     *
     * @return list<int>
     */
    public function years(): array;

    /**
     * Alle Fassungen eines Budgetplans, die erste zuerst.
     *
     * @return list<Budget>
     */
    public function iterationsOf(int $number): array;

    /**
     * Die beschlossenen Plaene eines Objekts.
     *
     * Fuer den Wirtschaftsplan: aus ihnen kommt die beschlossene Zufuehrung
     * zur Ruecklage.
     *
     * @return list<Budget>
     */
    public function decidedFor(string $propertyId): array;

    /**
     * Wer zugestimmt hat.
     *
     * @return list<string> Kennungen der Einheiten
     */
    public function approvalsOf(string $budgetId): array;

    /**
     * @param list<string> $unitIds
     */
    public function replaceApprovals(string $budgetId, array $unitIds): void;
}
