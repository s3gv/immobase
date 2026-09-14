<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\MissingFigure;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanProposal;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Property\Contract\UnitHouseholds;
use App\Module\Tenancy\Contract\TenancySpans;

/**
 * Die Berechnung eines Wirtschaftsplans.
 *
 * Einmal geschrieben, zweimal benutzt — genau wie bei der Abrechnung: die
 * Vorschau rechnet damit, und die Freigabe friert deren Ergebnis ein. Was der
 * Mensch geprueft hat, ist was gespeichert wird.
 *
 * Hier steht nur der Ablauf. Die Zeilen macht {@see PlannedCosts}, die
 * Verteilung {@see ShareOutCosts}, die Empfaenger {@see OwnersOnADay}, und
 * die Zeilen je Einheit setzt {@see PlanLines} zusammen.
 */
final readonly class ComposePlan
{
    public function __construct(
        private UnitDirectory $units,
        private TenancySpans $spans,
        private UnitHouseholds $households,
        private PlannedCosts $costs,
        private ShareOutCosts $shareOut,
        private OwnersOnADay $recipients,
        private PlanLines $lines,
    ) {
    }

    public function of(Plan $plan): PlanProposal
    {
        $units = $this->unitsOf($plan);
        $shared = $this->shared($plan, $units);
        $whom = $this->recipients->on($units, $plan->period()->from());
        $documents = [];
        $missing = [...$shared['missing'], ...PlanGaps::of($plan, $units, $whom)];

        foreach ($units as $unit) {
            $addressed = $whom[$unit->id] ?? null;

            if (null !== $addressed) {
                $documents[] = $this->lines->documentFor($plan, $unit, $shared['amounts'][$unit->id] ?? [], $addressed);
            }
        }

        return new PlanProposal($documents, $missing);
    }

    /**
     * Die Verteilung ueber das ganze Planjahr.
     *
     * Mietzeiten und Personenzahlen kommen mit, weil es Schluessel gibt, die
     * daran haengen. Tagesanteilig gerechnet wird trotzdem nie — das
     * entscheidet {@see PlannedCosts} mit `splitsByDay: false`.
     *
     * @param list<UnitBrief> $units
     *
     * @return array{amounts: array<string, array<string, ProposedLine>>, missing: list<MissingFigure>}
     */
    private function shared(Plan $plan, array $units): array
    {
        $ids = array_map(static fn (UnitBrief $unit): string => $unit->id, $units);
        $from = $plan->period()->from();
        $to = $plan->period()->to();

        return $this->shareOut->of(
            $this->costs->of($plan),
            $units,
            $this->spans->inPeriod($ids, $from, $to),
            $this->households->inPeriod($ids, $from, $to),
            $from,
            $to,
        );
    }

    /**
     * @return list<UnitBrief> nach Nummer sortiert
     */
    private function unitsOf(Plan $plan): array
    {
        $units = $this->units->ofProperty($plan->propertyNumber());
        usort($units, static fn (UnitBrief $one, UnitBrief $other): int => $one->number <=> $other->number);

        return $units;
    }
}
