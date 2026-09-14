<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeRentInvoice;
use App\Module\Billing\Application\RentInvoiceAsEInvoice;
use App\Module\Billing\Application\StatementAsEInvoice;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementRepository;
use App\Shared\EInvoice\CiiWriter;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die E-Rechnung als Datei — neben dem PDF.
 *
 * Nur fuer Belege mit Umsatzsteuer, die ausgestellt sind und ihre Angaben
 * eingefroren haben. Alles andere ist hier nicht zu finden: eine
 * E-Rechnung ueber steuerfreien Wohnraum waere eine Rechnung, die es nicht
 * geben darf, und eine ueber einen Entwurf eine Nummer, die nicht gilt.
 *
 * Wir verschicken nicht selbst; die Datei geht so an den Mieter, wie das PDF
 * heute auch.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class EInvoiceController extends AbstractController
{
    public function __construct(
        private readonly RequireRentInvoice $invoice,
        private readonly ComposeRentInvoice $compose,
        private readonly RentInvoiceAsEInvoice $rentInvoice,
        private readonly StatementRepository $statements,
        private readonly StatementAsEInvoice $statement,
        private readonly CiiWriter $writer,
    ) {
    }

    #[Route(
        '/billing/dauermietrechnungen/{id}/xrechnung',
        name: 'app_billing_invoice_xrechnung',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function rentInvoice(string $id): Response
    {
        $invoice = ($this->invoice)($id);

        try {
            $eInvoice = $this->rentInvoice->of($invoice, $this->compose->of($invoice));
        } catch (LogicException $none) {
            throw $this->createNotFoundException($none->getMessage());
        }

        return $this->fileFrom($this->writer->write($eInvoice), $invoice->reference());
    }

    #[Route(
        '/billing/abrechnungen/{id}/dokumente/{document}/xrechnung',
        name: 'app_billing_statement_xrechnung',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'document' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function statementDocument(string $id, string $document): Response
    {
        $statement = $this->statements->byId($id) ?? throw $this->createNotFoundException('Diese Abrechnung gibt es nicht.');
        $found = array_values(array_filter(
            $statement->documents(),
            static fn (StatementDocument $candidate): bool => $candidate->id() === $document,
        ))[0] ?? throw $this->createNotFoundException('Dieses Schreiben gehört nicht zu dieser Abrechnung.');

        try {
            $eInvoice = $this->statement->of($statement, $found);
        } catch (LogicException $none) {
            throw $this->createNotFoundException($none->getMessage());
        }

        return $this->fileFrom($this->writer->write($eInvoice), $found->reference()->toString());
    }

    private function fileFrom(string $xml, string $reference): Response
    {
        $response = new Response($xml);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, str_replace('/', '-', $reference).'.xml'),
        );

        return $response;
    }
}
