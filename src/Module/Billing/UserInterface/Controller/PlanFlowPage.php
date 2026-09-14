<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\Plan;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Planschrittes braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class PlanFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Der Rahmen eines Schrittes.
     *
     * `$editable` entscheidet, ob die Schritte Links sind. Ein freigegebener
     * Plan wird im selben Rahmen angesehen, in dem er entstanden ist — aber
     * zurueckspringen kann man nicht mehr, und ein Link, der zuverlaessig in
     * eine Absage fuehrt, ist schlechter als keiner.
     *
     * @return array<string, mixed>
     */
    public function frame(?Plan $plan, string $step, bool $editable = true): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($editable ? $plan : null, $key),
                PlanFlow::keys(),
            ),
            'current' => $step,
            'title' => $this->translator->trans('billing.plan.step.'.$step),
            'explanation' => $this->translator->trans('billing.plan.explanation.'.$step),
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => PlanFlow::positionOf($step),
                '%count%' => PlanFlow::count(),
            ]),
            'heading' => $this->translator->trans('billing.plan.heading'),
            'subheading' => $this->name($plan),
            'hasPrevious' => null !== PlanFlow::previous($step),
            'isLast' => null === PlanFlow::next($step),
            'action' => null === $plan
                ? $this->urls->generate('app_billing_plan_new')
                : $this->urls->generate('app_billing_plan_edit', ['id' => $plan->id(), 'step' => $step]),
            'cancel' => $this->urls->generate('app_billing_plan'),
        ];
    }

    /** Objekt und Planjahr — und bei einer Korrektur, dass es eine ist. */
    private function name(?Plan $plan): string
    {
        if (null === $plan) {
            return $this->translator->trans('billing.plan.new');
        }

        $name = $plan->propertyNumber().' · '.$plan->period()->year();

        return $plan->edition()->isCorrection()
            ? $name.' · '.$this->translator->trans('billing.plan.correction')
            : $name;
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(?Plan $plan, string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('billing.plan.step.'.$key),
            'url' => null === $plan ? null : $this->urls->generate(
                'app_billing_plan_edit',
                ['id' => $plan->id(), 'step' => $key],
            ),
        ];
    }
}
