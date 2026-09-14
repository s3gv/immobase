<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanDocument;
use App\Module\Billing\Domain\PlannedDocument;
use App\Module\Billing\Domain\PlanRepository;

/**
 * Welche Wirtschaftsplaene eine Korrektur brauchen.
 *
 * Nachgerechnet wird der heutige Stand und mit dem eingefrorenen verglichen —
 * **je Einheit und nur der Vorschuss**. Eine umbenannte Kostenart meldet sich
 * nicht; eine Meldung bedeutet immer, dass jemand ab einem Tag etwas anderes
 * zahlen muesste als das, was beschlossen wurde.
 *
 * Der Anlass ist haeufiger als bei der Abrechnung: Miteigentumsanteile werden
 * berichtigt, eine Einheit kommt dazu, ein Schluessel aendert sich. Ein Plan
 * gilt ein ganzes Jahr, und in einem Jahr passiert so etwas.
 */
final readonly class CheckPlanCorrections
{
    public function __construct(
        private PlanRepository $plans,
        private ComposePlan $compose,
    ) {
    }

    /**
     * @return array<string, int> Kennung des Plans auf die Zahl der betroffenen Einheiten
     */
    public function pending(): array
    {
        $found = [];

        foreach (self::latestOnly($this->plans->released()) as $plan) {
            $affected = \count($this->changed($plan));

            if ($affected > 0) {
                $found[$plan->id()] = $affected;
            }
        }

        return $found;
    }

    /**
     * Die Einheiten, deren Vorschuss heute anders ausfiele.
     *
     * @return list<PlannedDocument>
     */
    public function changed(Plan $plan): array
    {
        if ($plan->stage()->isOpen()) {
            return [];
        }

        $frozen = [];

        foreach ($plan->documents() as $document) {
            $frozen[$document->unitId()] = $document;
        }

        $changed = [];

        foreach ($this->compose->of($plan)->documents as $today) {
            $before = $frozen[$today->key()] ?? null;

            if (null === $before || !self::samePayment($before, $today)) {
                $changed[] = $today;
            }
        }

        return $changed;
    }

    /** Derselbe Jahresanteil und derselbe Vorschuss — mehr wird nicht verglichen. */
    private static function samePayment(PlanDocument $before, PlannedDocument $today): bool
    {
        return $before->yearly()->equals($today->yearly())
            && $before->advance()->equals($today->advance());
    }

    /**
     * Von jedem Plan nur die juengste Iteration.
     *
     * Ein korrigierter Plan meldete sich sonst weiter — er ist ja unveraendert
     * ueberholt. Beantwortet ist er trotzdem: von seiner Korrektur.
     *
     * @param list<Plan> $released
     *
     * @return list<Plan>
     */
    private static function latestOnly(array $released): array
    {
        $latest = [];

        foreach ($released as $plan) {
            $known = $latest[$plan->edition()->number()] ?? null;

            if (null === $known || $known->edition()->iteration() < $plan->edition()->iteration()) {
                $latest[$plan->edition()->number()] = $plan;
            }
        }

        return array_values($latest);
    }
}
