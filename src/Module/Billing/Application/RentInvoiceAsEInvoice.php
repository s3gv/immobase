<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\ProposedInvoice;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Shared\EInvoice\EInvoice;
use App\Shared\EInvoice\EInvoiceLine;
use LogicException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die ausgestellte Dauermietrechnung als E-Rechnung.
 *
 * Gelesen wird nur, was eingefroren ist — dieselbe Gestalt, aus der das PDF
 * gezeichnet wird. Die Positionen sind monatlich, der Leistungszeitraum ist
 * der der Rechnung, ein offenes Ende bleibt offen: bei einem
 * Dauerschuldverhaeltnis genuegt eine E-Rechnung fuer den ersten Zeitraum mit
 * Vertragsbezug (BMF-Schreiben vom 15.10.2024).
 *
 * **Deutsch, gleich in welcher Sprache die Oberflaeche laeuft.** Die Rechnung
 * geht an ein deutsches Unternehmen und an dessen Finanzamt.
 */
final readonly class RentInvoiceAsEInvoice
{
    public function __construct(
        private RentInvoiceRepository $invoices,
        private TranslatorInterface $translator,
        private EInvoiceTerms $terms,
    ) {
    }

    /**
     * @throws LogicException fuer einen Entwurf, eine steuerfreie Rechnung oder eine ohne eingefrorene Angaben
     */
    public function of(RentInvoice $invoice, ProposedInvoice $body): EInvoice
    {
        $issuedOn = $invoice->release()->day();
        $data = $body->eInvoice;

        if (null === $issuedOn || !$body->taxation->isCharged() || !$data->isCaptured()) {
            throw new LogicException('Für diese Dauermietrechnung gibt es keine E-Rechnung.');
        }

        return new EInvoice(
            number: $invoice->reference(),
            issuedOn: $issuedOn,
            seller: EInvoiceParties::seller($body->landlordName, $data, $body->landlordTaxNumber),
            buyer: EInvoiceParties::buyer($body->tenantName, $data),
            contactName: $data->contact()['name'],
            contactPhone: $data->contact()['phone'],
            contactEmail: $data->contact()['email'],
            buyerReference: $data->buyerReference(),
            contractReference: (string) $invoice->tenancyNumber(),
            periodFrom: $invoice->validity()->from(),
            periodTo: $invoice->validity()->until(),
            lines: $this->linesOf($body),
            rateBps: $body->taxation->rateBps(),
            payment: $this->terms->forRent($data, $body->payeeIban, $body->payeeName),
            notes: $this->notes($invoice, $body),
            precedingNumber: $this->precedingOf($invoice),
            payeeName: $body->payeeName === $body->landlordName ? '' : $body->payeeName,
        );
    }

    /**
     * @return list<EInvoiceLine>
     */
    private function linesOf(ProposedInvoice $body): array
    {
        return array_map(fn (array $line): EInvoiceLine => new EInvoiceLine(
            $this->german('billing.invoice.line.'.$line['key']),
            $line['amount'],
            EInvoiceLine::MONTH,
        ), $body->lines());
    }

    /**
     * @return list<string>
     */
    private function notes(RentInvoice $invoice, ProposedInvoice $body): array
    {
        $until = $invoice->validity()->until();

        return [
            $this->german('billing.einvoice.note.standing', [
                '%from%' => $invoice->validity()->from()->format('d.m.Y'),
                '%until%' => null === $until ? $this->german('billing.einvoice.note.open_ended') : $until->format('d.m.Y'),
                '%tenancy%' => (string) $invoice->tenancyNumber(),
                '%let%' => $body->letLabel,
            ]),
            $this->german('billing.invoice.pdf.option'),
        ];
    }

    private function precedingOf(RentInvoice $invoice): ?string
    {
        $corrects = $invoice->edition()->correctsId();

        return null === $corrects ? null : $this->invoices->byId($corrects)?->reference();
    }

    /**
     * @param array<string, string> $parameters
     */
    private function german(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, null, 'de');
    }
}
