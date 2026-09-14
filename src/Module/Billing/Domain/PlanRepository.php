<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Ui\Page;

interface PlanRepository
{
    public function save(Plan $plan): void;

    /** Nur Entwuerfe — auf einem freigegebenen Plan stehen Forderungen. */
    public function remove(Plan $plan): void;

    /**
     * Alles oder nichts.
     *
     * Die Freigabe greift ueber Modulgrenzen durch: der Plan wird beschlossen,
     * und je Einheit entsteht eine Hausgeldstufe. Beides gehoert zusammen —
     * ein beschlossener Plan, dessen Staffel nur halb geschrieben ist, waere
     * schlimmer als der Zustand vorher, denn der Status sperrt einen zweiten
     * Versuch.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;

    public function byId(string $id): ?Plan;

    /** Die naechste sichtbare Nummer, aus der Sequenz. */
    public function nextNumber(): int;

    public function countMatching(PlanFilter $filter): int;

    /**
     * @return list<Plan> die neuesten zuerst
     */
    public function matching(PlanFilter $filter, Page $page): array;

    /**
     * Die Planjahre, zu denen es Plaene gibt — die juengsten zuerst.
     *
     * @return list<int>
     */
    public function years(): array;

    /**
     * @return list<Plan> alle freigegebenen, fuer die Korrekturpruefung
     */
    public function released(): array;

    /**
     * Alle Iterationen eines Plans, der erste zuerst.
     *
     * @return list<Plan>
     */
    public function iterationsOf(int $number): array;

    /**
     * @return list<PlanSource>
     */
    public function sourcesOf(string $planId): array;

    /**
     * @param list<PlanSource> $sources
     */
    public function replaceSources(string $planId, array $sources): void;

    /**
     * Welche dieser Quellen von einem Plan benutzt werden.
     *
     * @param list<string> $sourceIds
     *
     * @return array<string, string> Quelle auf die Bezeichnung des Plans, der sie haelt
     */
    public function holders(array $sourceIds): array;
}
