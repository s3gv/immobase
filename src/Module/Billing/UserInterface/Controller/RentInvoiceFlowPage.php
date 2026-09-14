<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\RentInvoice;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Rechnungsschrittes braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class RentInvoiceFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Der Rahmen eines Schrittes.
     *
     * `$editable` entscheidet, ob die Schritte Links sind. Eine ausgestellte
     * Rechnung wird im selben Rahmen angesehen, in dem sie entstanden ist —
     * aber zurueckspringen kann man nicht mehr, und ein Link, der
     * zuverlaessig in eine Absage fuehrt, ist schlechter als keiner.
     *
     * @return array<string, mixed>
     */
    public function frame(?RentInvoice $invoice, string $step, bool $editable = true): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($editable ? $invoice : null, $key),
                RentInvoiceFlow::keys(),
            ),
            'current' => $step,
            'title' => $this->translator->trans('billing.invoice.step.'.RentInvoiceFlow::name($step)),
            'explanation' => $this->translator->trans('billing.invoice.explanation.'.RentInvoiceFlow::name($step)),
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => RentInvoiceFlow::positionOf($step),
                '%count%' => RentInvoiceFlow::count(),
            ]),
            'heading' => $this->translator->trans('billing.invoice.heading'),
            'subheading' => $this->name($invoice),
            'hasPrevious' => null !== RentInvoiceFlow::previous($step),
            'isLast' => null === RentInvoiceFlow::next($step),
            'action' => null === $invoice
                ? $this->urls->generate('app_billing_invoice_new')
                : $this->urls->generate('app_billing_invoice_edit', ['id' => $invoice->id(), 'step' => $step]),
            'cancel' => $this->urls->generate('app_billing_invoice'),
        ];
    }

    /** Die Rechnungsnummer — und bei einer Berichtigung, dass es eine ist. */
    private function name(?RentInvoice $invoice): string
    {
        if (null === $invoice) {
            return $this->translator->trans('billing.invoice.new');
        }

        return $invoice->edition()->isCorrection()
            ? $invoice->reference().' · '.$this->translator->trans('billing.invoice.correction')
            : $invoice->reference();
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(?RentInvoice $invoice, string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('billing.invoice.step.'.RentInvoiceFlow::name($key)),
            'url' => null === $invoice ? null : $this->urls->generate(
                'app_billing_invoice_edit',
                ['id' => $invoice->id(), 'step' => $key],
            ),
        ];
    }
}
