<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeRentInvoice;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\UserInterface\Pdf\RentInvoiceLetter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Dauermietrechnung als PDF.
 *
 * **Ein Empfaenger, ein Blatt** — damit das einzige Schreiben des Moduls, das
 * kein Archiv braucht. Abrechnung, Plan und Bericht gehen an alle Einheiten
 * eines Objekts; diese geht an einen Mieter.
 *
 * Nur ausgestellte: ein Entwurf hat keine Rechnungsnummer, die gilt, und ein
 * Blatt mit einer Nummer ist im Umlauf, sobald es jemand ausgedruckt hat.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class RentInvoicePdfController extends AbstractController
{
    public function __construct(
        private readonly RequireRentInvoice $invoice,
        private readonly ComposeRentInvoice $compose,
        private readonly RentInvoiceLetter $letter,
    ) {
    }

    #[Route(
        '/billing/dauermietrechnungen/{id}/pdf',
        name: 'app_billing_invoice_pdf',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function pdf(string $id): Response
    {
        $invoice = ($this->invoice)($id);
        $issued = $invoice->release()->day();

        if (null === $issued) {
            throw $this->createNotFoundException('Aus einem Entwurf entsteht keine Rechnung.');
        }

        return $this->fileFrom(
            $this->letter->of($invoice, $this->compose->of($invoice), $issued),
            $invoice->reference().'.pdf',
        );
    }

    private function fileFrom(string $bytes, string $name): Response
    {
        $response = new Response($bytes);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name),
        );

        return $response;
    }
}
