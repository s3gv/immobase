<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanFilter;
use App\Module\Billing\Domain\PlanRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Was auf der Uebersicht ueber die Wirtschaftsplaene steht.
 *
 * Zwei Fragen, die eine Verwaltung im Herbst hat: was ist angefangen, und was
 * ist beschlossen. Dazu die dritte, die sie nicht stellt, aber beantwortet
 * bekommen muss — welcher Plan seit seiner Freigabe nicht mehr stimmt.
 */
final readonly class SurveyPlans
{
    public function __construct(
        private PlanRepository $plans,
        private CheckPlanCorrections $corrections,
    ) {
    }

    /**
     * @return array{drafts: int, released: int, advances: Money, year: int, corrections: array<string, int>, correctable: list<Plan>}
     */
    public function overview(): array
    {
        $released = $this->plans->released();
        $pending = $this->corrections->pending();

        return [
            'drafts' => $this->plans->countMatching(PlanFilter::draftsOnly()),
            'released' => \count($released),
            'advances' => self::advancesFor(self::yearToPlan(), $released),
            'year' => self::yearToPlan(),
            'corrections' => $pending,
            'correctable' => array_values(array_filter(
                $released,
                static fn (Plan $plan): bool => isset($pending[$plan->id()]),
            )),
        ];
    }

    /**
     * Das Jahr, fuer das jetzt geplant wird.
     *
     * Ein Wirtschaftsplan wird vor Beginn des Jahres aufgestellt, ueber das er
     * geht. Ab Juli ist das naechste Jahr gemeint; davor noch das laufende, zu
     * dem es womoeglich noch keinen gibt.
     */
    public static function yearToPlan(): int
    {
        $today = new DateTimeImmutable('today');
        $year = (int) $today->format('Y');

        return (int) $today->format('n') >= 7 ? $year + 1 : $year;
    }

    /**
     * Was je Zahlung hereinkommt — ueber alle Plaene dieses Jahres.
     *
     * Je Plannummer nur die juengste Iteration: eine korrigierte Fassung
     * ersetzt ihre Vorgaengerin, sie kommt nicht dazu.
     *
     * @param list<Plan> $released
     */
    private static function advancesFor(int $year, array $released): Money
    {
        $total = Money::zero();

        foreach (self::latestOnly($released) as $plan) {
            if ($plan->period()->year() !== $year) {
                continue;
            }

            foreach ($plan->documents() as $document) {
                $total = $total->plus($document->advance());
            }
        }

        return $total;
    }

    /**
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
