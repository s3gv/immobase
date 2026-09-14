<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanIsIncomplete;
use App\Module\Billing\Domain\PlanIsReleased;
use App\Module\Billing\Domain\PlanRepository;
use DateTimeImmutable;

/**
 * Die Beschlussvorlage geht heraus — und wird dabei eingefroren.
 *
 * **Das Einfrieren ist der ganze Punkt.** „Herausgegeben am 30. Oktober" ist
 * eine Auskunft darueber, *was* an diesem Tag vorlag. Rechnete das Blatt beim
 * Herunterladen jedes Mal neu, koennte jede spaetere Aenderung an der
 * Grundlage es veraendern — ein berichtigter Miteigentumsanteil, ein
 * nachgetragener Verbrauch, ein Eigentuemerwechsel, eine neue Anschrift. Der
 * Plan selbst bliebe unberuehrt, und trotdem stuende auf dem Blatt etwas
 * anderes als das, was die Eigentuemer bekommen haben.
 *
 * Eingefroren wird darum genau das, was auch die Freigabe einfrieren wuerde:
 * dieselben Schreiben, dieselbe Maschinerie ({@see FreezePlan}). Der
 * Unterschied ist allein der Zustand — beschlossen ist damit nichts.
 *
 * Eine Vorlage mit Luecken gibt es nicht. Wer ein Blatt herausgibt, auf dem
 * eine Angabe fehlt, laesst die Versammlung ueber eine Zahl abstimmen, die
 * niemand nachrechnen kann.
 */
final readonly class ProposePlan
{
    public function __construct(
        private PlanRepository $plans,
        private ComposePlan $compose,
    ) {
    }

    /**
     * @throws PlanIsReleased
     * @throws PlanIsIncomplete
     */
    public function propose(Plan $plan, DateTimeImmutable $on): void
    {
        if (!$plan->stage()->isOpen()) {
            throw PlanIsReleased::already();
        }

        $proposal = $this->compose->of($plan);

        if (!$proposal->isComplete()) {
            throw PlanIsIncomplete::figuresAreMissing();
        }

        if ($proposal->isEmpty()) {
            throw PlanIsIncomplete::thereIsNothingToSend();
        }

        FreezePlan::of($plan, $proposal);
        $plan->proposeOn($on);
        $this->plans->save($plan);
    }
}
