<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\Budget;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Budgetschrittes braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class BudgetFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function frame(?Budget $budget, string $step, bool $editable = true): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($editable ? $budget : null, $key),
                BudgetFlow::keys(),
            ),
            'current' => $step,
            'title' => $this->translator->trans('billing.budget.step.'.$step),
            'explanation' => $this->translator->trans('billing.budget.explanation.'.$step),
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => BudgetFlow::positionOf($step),
                '%count%' => BudgetFlow::count(),
            ]),
            'heading' => $this->translator->trans('billing.budget.heading'),
            'subheading' => $this->name($budget),
            'hasPrevious' => null !== BudgetFlow::previous($step),
            'isLast' => null === BudgetFlow::next($step),
            'action' => null === $budget
                ? $this->urls->generate('app_billing_budget_new')
                : $this->urls->generate('app_billing_budget_edit', ['id' => $budget->id(), 'step' => $step]),
            'cancel' => $this->urls->generate('app_billing_budget'),
        ];
    }

    /** Die Massnahme und ihr Jahr — und bei einer Berichtigung, dass es eine ist. */
    private function name(?Budget $budget): string
    {
        if (null === $budget) {
            return $this->translator->trans('billing.budget.new');
        }

        $name = $budget->propertyNumber().' · '.$budget->measure()->firstYear();

        return $budget->edition()->isCorrection()
            ? $name.' · '.$this->translator->trans('billing.budget.correction')
            : $name;
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(?Budget $budget, string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('billing.budget.step.'.$key),
            'url' => null === $budget ? null : $this->urls->generate(
                'app_billing_budget_edit',
                ['id' => $budget->id(), 'step' => $key],
            ),
        ];
    }
}
