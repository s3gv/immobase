<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\IssueRentInvoice;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceIsIncomplete;
use App\Module\Billing\Domain\RentInvoiceIsIssued;
use App\Module\Billing\Domain\RentInvoiceIsOutOfOrder;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf: eine Dauermietrechnung anlegen und ausstellen.
 *
 * Wie ueberall speichert jeder Schritt sofort. Zu speichern gibt es aber nur
 * im ersten etwas — die drei folgenden zeigen, was anderswo steht, und der
 * letzte stellt aus.
 */
#[IsGranted(BillingPermissions::EDIT)]
final class RentInvoiceFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireRentInvoice $invoice,
        private readonly RentInvoiceStepInput $input,
        private readonly RentInvoiceFlowPage $page,
        private readonly RentInvoiceView $view,
        private readonly IssueRentInvoice $issue,
    ) {
    }

    #[Route('/billing/dauermietrechnungen/neu', name: 'app_billing_invoice_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->show(null, RentInvoiceFlow::BASICS, $request);
        }

        $this->guard($request);
        $created = $this->input->create($request);

        if (null === $created['invoice']) {
            return $this->show(null, RentInvoiceFlow::BASICS, $request, $created['errors']);
        }

        $this->addFlash('success', 'billing.invoice.created');

        return $this->toStep($created['invoice'], RentInvoiceFlow::AMOUNTS);
    }

    #[Route(
        '/billing/dauermietrechnungen/{id}/bearbeiten/{step}',
        name: 'app_billing_invoice_edit',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'step' => '[a-z]+'],
        defaults: ['step' => RentInvoiceFlow::BASICS],
        methods: ['GET', 'POST'],
    )]
    public function edit(string $id, string $step, Request $request): Response
    {
        $invoice = ($this->invoice)($id);
        $current = RentInvoiceFlow::known($step);

        if (!$invoice->release()->isDraft()) {
            return $this->redirectToRoute('app_billing_invoice_show', ['id' => $invoice->id()]);
        }

        if (!$request->isMethod('POST')) {
            return $this->show($invoice, $current, $request);
        }

        $this->guard($request);
        $errors = $this->input->apply($current, $request, $invoice);

        if ([] !== $errors) {
            return $this->show($invoice, $current, $request, $errors);
        }

        return $this->onwards($invoice, $current, $request);
    }

    /**
     * Ausstellen — unumkehrbar.
     *
     * Danach steht die Rechnung beim Mieter und traegt eine Nummer, mit der
     * er Vorsteuer zieht. Was daran falsch ist, wird berichtigt.
     */
    #[Route(
        '/billing/dauermietrechnungen/{id}/ausstellen',
        name: 'app_billing_invoice_issue',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function issue(string $id, Request $request): Response
    {
        $invoice = ($this->invoice)($id);
        $this->guard($request);

        try {
            $this->issue->issue($invoice, new DateTimeImmutable('today'));
            $this->addFlash('success', 'billing.invoice.issued');
        } catch (RentInvoiceIsIssued|RentInvoiceIsIncomplete|RentInvoiceIsOutOfOrder $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToRoute('app_billing_invoice_show', ['id' => $invoice->id()]);
    }

    private function onwards(RentInvoice $invoice, string $step, Request $request): Response
    {
        if ('back' === $request->request->getString('direction')) {
            return $this->toStep($invoice, RentInvoiceFlow::previous($step) ?? RentInvoiceFlow::BASICS);
        }

        $next = RentInvoiceFlow::next($step);

        if (null !== $next) {
            return $this->toStep($invoice, $next);
        }

        $this->addFlash('success', 'billing.invoice.saved');

        return $this->redirectToRoute('app_billing_invoice_show', ['id' => $invoice->id()]);
    }

    private function toStep(RentInvoice $invoice, string $step): Response
    {
        return $this->redirectToRoute('app_billing_invoice_edit', ['id' => $invoice->id(), 'step' => $step]);
    }

    /**
     * @param array<string, string> $errors
     */
    private function show(?RentInvoice $invoice, string $step, Request $request, array $errors = []): Response
    {
        return $this->render('billing/invoice/'.$step.'.html.twig', [
            ...$this->page->frame($invoice, $step),
            ...(null === $invoice ? [] : $this->view->data($invoice)),
            'errors' => $errors,
            'submitted' => [] === $errors ? [] : $request->request->all(),
            'tenancies' => null === $invoice ? $this->view->lettable() : [],
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('billing_invoice', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
