<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\BudgetFilter;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\PlanFilter;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\ResolutionStatus;
use App\Module\Billing\Domain\StatementFilter;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Billing\Domain\StatementStatus;
use App\Shared\Figure\ContributesFigures;
use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Wie weit das laufende Abrechnungsjahr ist.
 *
 * Drei Zahlen, drei zaehlende Abfragen — und kein Verhaeltnis dazu. „5 von 8"
 * braeuchte eine Bezugsgroesse, und die gaebe es nur als Annahme: nicht jedes
 * Objekt bekommt jedes Jahr jedes Schreiben. Eine erfundene Bezugsgroesse ist
 * schlechter als keine, weil sie aussieht wie eine Auskunft.
 */
#[AsTaggedItem(priority: 70)]
final readonly class BillingFigures implements ContributesFigures
{
    public function __construct(
        private StatementRepository $statements,
        private PlanRepository $plans,
        private BudgetRepository $budgets,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function figures(): array
    {
        if (!$this->mayView->isGranted(BillingPermissions::VIEW)) {
            return [];
        }

        $year = (string) (int) (new DateTimeImmutable('today'))->format('Y');
        $released = StatementStatus::Released->value;

        return [
            $this->figure(
                'figure.billing.statements',
                $this->statements->countMatching(StatementFilter::of(null, $year, $released, null)),
                'app_billing_statement',
                ['jahr' => $year, 'zustand' => $released],
            ),
            $this->figure(
                'figure.billing.plans',
                $this->plans->countMatching(PlanFilter::of(null, $year, $released, null)),
                'app_billing_plan',
                ['jahr' => $year, 'zustand' => $released],
            ),
            $this->figure(
                'figure.billing.budgets',
                $this->budgets->countMatching(BudgetFilter::of(null, $year, ResolutionStatus::Released->value, null)),
                'app_billing_budget',
                ['jahr' => $year, 'zustand' => ResolutionStatus::Released->value],
            ),
        ];
    }

    /**
     * @param array<string, string> $query
     */
    private function figure(string $labelKey, int $count, string $route, array $query): Figure
    {
        return new Figure(
            group: FigureGroup::Year,
            labelKey: $labelKey,
            value: (string) $count,
            url: $this->urls->generate($route, $query),
        );
    }
}
