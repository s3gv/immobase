<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ReviseRentInvoice;
use App\Module\Billing\Application\StartRentInvoice;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceCannotBeCorrected;
use App\Module\Billing\Domain\RentInvoiceFilter;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Ui\Page;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Dauermietrechnungen: Uebersicht, Ansicht, Berichtigung, Folgefassung.
 *
 * Anlegen und Ausstellen laufen ueber den Ablauf nebenan — das Nachschlagen
 * ist eine andere Sache.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class RentInvoiceController extends AbstractController
{
    public function __construct(
        private readonly RentInvoiceRepository $invoices,
        private readonly RequireRentInvoice $invoice,
        private readonly RentInvoiceView $view,
        private readonly RentInvoiceFlowPage $flow,
        private readonly ReviseRentInvoice $revise,
        private readonly StartRentInvoice $start,
        private readonly BillingPage $page,
        private readonly PropertyDirectory $properties,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/billing/dauermietrechnungen', name: 'app_billing_invoice', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = RentInvoiceFilter::of(
            $request->query->getString('objekt'),
            $request->query->getString('status'),
            $request->query->getString('q'),
        );
        $page = Page::of($request->query->getInt('page', 1), $this->invoices->countMatching($filter));
        $invoices = $this->invoices->matching($filter, $page);

        return $this->render('billing/invoices.html.twig', [
            'invoices' => $invoices,
            'corrected' => array_flip($this->invoices->correctedAmong(
                array_map(static fn (RentInvoice $invoice): string => $invoice->id(), $invoices),
            )),
            'page' => $page,
            'filter' => $filter,
            'url' => $this->listUrl($request),
            'properties' => $this->properties->all(),
            'trail' => $this->page->trail('billing.invoice.heading'),
        ]);
    }

    /**
     * Eine ausgestellte Rechnung ansehen.
     *
     * Im Rahmen des Ablaufs, auf dem letzten Schritt: wer sie gerade noch
     * geprueft hat, findet dieselbe Seite wieder — nur ohne Knoepfe.
     */
    #[Route(
        '/billing/dauermietrechnungen/{id}',
        name: 'app_billing_invoice_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): Response
    {
        $invoice = ($this->invoice)($id);

        if ($invoice->release()->isDraft()) {
            return $this->redirectToRoute('app_billing_invoice_edit', ['id' => $invoice->id()]);
        }

        return $this->render('billing/invoice.html.twig', [
            ...$this->flow->frame($invoice, RentInvoiceFlow::ISSUE, editable: false),
            ...$this->view->data($invoice),
            'title' => $this->translator->trans('billing.invoice.issued_title'),
            'explanation' => $this->translator->trans('billing.invoice.issued_explanation'),
            'trail' => $this->page->trail('billing.invoice.heading'),
        ]);
    }

    /** Die berichtigte Fassung — derselbe Zeitraum, eigene Nummer. */
    #[IsGranted(BillingPermissions::EDIT)]
    #[Route(
        '/billing/dauermietrechnungen/{id}/berichtigen',
        name: 'app_billing_invoice_correct',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function correct(string $id, Request $request): Response
    {
        $invoice = ($this->invoice)($id);
        $this->guard($request);

        $open = $this->revise->openCorrectionOf($invoice);

        if (null !== $open) {
            return $this->backToTheDraft($open, 'billing.error.invoice_corrects_a_draft');
        }

        try {
            $correction = $this->revise->correct($invoice);
        } catch (RentInvoiceCannotBeCorrected $problem) {
            $this->addFlash('error', $problem->getMessage());

            return $this->redirectToRoute('app_billing_invoice_show', ['id' => $invoice->id()]);
        }

        return $this->redirectToRoute('app_billing_invoice_edit', ['id' => $correction->id()]);
    }

    /** Die Folgefassung — ab dem Tag, an dem sich etwas aendert. */
    #[IsGranted(BillingPermissions::EDIT)]
    #[Route(
        '/billing/dauermietrechnungen/{id}/neu-ausstellen',
        name: 'app_billing_invoice_succeed',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function succeed(string $id, Request $request): Response
    {
        $invoice = ($this->invoice)($id);
        $this->guard($request);
        $open = $this->revise->openSuccessorFor($invoice->tenancyId());

        if (null !== $open) {
            return $this->backToTheDraft($open, 'billing.error.invoice_succeeds_a_draft');
        }

        $next = $this->revise->succeed($invoice, $this->revise->nextChangeAfter($invoice));

        return $this->redirectToRoute('app_billing_invoice_edit', ['id' => $next->id()]);
    }

    /** Ein Entwurf hat nie gegolten — er wird geloescht. */
    #[IsGranted(BillingPermissions::EDIT)]
    #[Route(
        '/billing/dauermietrechnungen/{id}/loeschen',
        name: 'app_billing_invoice_delete',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function delete(string $id, Request $request): Response
    {
        $invoice = ($this->invoice)($id);
        $this->guard($request);

        if (!$invoice->release()->isDraft()) {
            $this->addFlash('error', 'billing.error.invoice_issued');

            return $this->redirectToRoute('app_billing_invoice_show', ['id' => $invoice->id()]);
        }

        $this->start->discard($invoice);
        $this->addFlash('success', 'billing.invoice.discarded');

        return $this->redirectToRoute('app_billing_invoice');
    }

    /**
     * Zurueck auf einen Entwurf, den es schon gibt — und gesagt, warum.
     *
     * Von beiden Wegen zur zweiten Fassung: je Weg gibt es hoechstens einen
     * Entwurf, und ein zweiter daneben waeren zwei Schreiben ueber denselben
     * Zeitraum. Wer klickt und auf einem Entwurf von vorletzter Woche
     * landet, haelt das sonst fuer einen Fehler.
     */
    private function backToTheDraft(RentInvoice $open, string $why): Response
    {
        $this->addFlash('info', $why);

        return $this->redirectToRoute('app_billing_invoice_edit', ['id' => $open->id()]);
    }

    /** Die Adresse der Liste mit Platzhalter fuer die Seitenzahl. */
    private function listUrl(Request $request): string
    {
        $parameters = [];

        foreach (['objekt', 'status', 'q'] as $name) {
            $value = trim($request->query->getString($name));

            if ('' !== $value) {
                $parameters[$name] = $value;
            }
        }

        return $this->generateUrl('app_billing_invoice', [...$parameters, 'page' => '__PAGE__']);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('billing_invoice', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
