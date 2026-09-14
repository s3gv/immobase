<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\AssetReport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Berichtsschrittes braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class AssetReportFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Der Rahmen eines Schrittes.
     *
     * `$editable` entscheidet, ob die Schritte Links sind. Ein herausgegebener
     * Bericht wird im selben Rahmen angesehen, in dem er entstanden ist — aber
     * zurueckspringen kann man nicht mehr, und ein Link, der zuverlaessig in
     * eine Absage fuehrt, ist schlechter als keiner.
     *
     * @return array<string, mixed>
     */
    public function frame(?AssetReport $report, string $step, bool $editable = true): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($editable ? $report : null, $key),
                AssetReportFlow::keys(),
            ),
            'current' => $step,
            'title' => $this->translator->trans('billing.report.step.'.$step),
            'explanation' => $this->translator->trans('billing.report.explanation.'.$step),
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => AssetReportFlow::positionOf($step),
                '%count%' => AssetReportFlow::count(),
            ]),
            'heading' => $this->translator->trans('billing.report.heading'),
            'subheading' => $this->name($report),
            'hasPrevious' => null !== AssetReportFlow::previous($step),
            'isLast' => null === AssetReportFlow::next($step),
            'action' => null === $report
                ? $this->urls->generate('app_billing_report_new')
                : $this->urls->generate('app_billing_report_edit', ['id' => $report->id(), 'step' => $step]),
            'cancel' => $this->urls->generate('app_billing_report'),
        ];
    }

    /** Objekt und Berichtsjahr — und bei einer Berichtigung, dass es eine ist. */
    private function name(?AssetReport $report): string
    {
        if (null === $report) {
            return $this->translator->trans('billing.report.new');
        }

        $name = $report->propertyNumber().' · '.$report->period()->year();

        return $report->edition()->isCorrection()
            ? $name.' · '.$this->translator->trans('billing.report.correction')
            : $name;
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(?AssetReport $report, string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('billing.report.step.'.$key),
            'url' => null === $report ? null : $this->urls->generate(
                'app_billing_report_edit',
                ['id' => $report->id(), 'step' => $key],
            ),
        ];
    }
}
