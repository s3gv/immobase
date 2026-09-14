<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\EInvoice;

use DOMElement;

/**
 * Der Abrechnungsteil einer CII-Rechnung — Zahlung, Steuer, Summen.
 *
 * Eigens, weil er der laengste Teil ist und seine Reihenfolge die strengste:
 * Glaeubiger-ID vor dem Verwendungszweck, die Zahlungsart vor der Steuer, die
 * Zahlungsbedingung vor den Summen, der Verweis auf die berichtigte Rechnung
 * ganz am Ende. So verlangt es das Schema D16B.
 */
final readonly class CiiSettlement
{
    public function __construct(private CiiWriter $writer)
    {
    }

    public function write(DOMElement $settlement, EInvoice $invoice): void
    {
        $payment = $invoice->payment;
        $w = $this->writer;

        if ($payment->isDirectDebit()) {
            $w->ram($settlement, 'CreditorReferenceID', $payment->creditorId);
        }

        $w->ram($settlement, 'PaymentReference', $invoice->number);
        $w->ram($settlement, 'InvoiceCurrencyCode', 'EUR');

        if ('' !== $invoice->payeeName) {
            $w->ram($w->ram($settlement, 'PayeeTradeParty'), 'Name', $invoice->payeeName);
        }

        $this->means($settlement, $payment);
        $w->taxOf($settlement, $invoice, true);
        $w->period($settlement, $invoice);
        $this->terms($settlement, $payment);
        $this->totals($settlement, $invoice);

        if (null !== $invoice->precedingNumber) {
            $w->ram($w->ram($settlement, 'InvoiceReferencedDocument'), 'IssuerAssignedID', $invoice->precedingNumber);
        }
    }

    private function means(DOMElement $settlement, EInvoicePayment $payment): void
    {
        $w = $this->writer;
        $means = $w->ram($settlement, 'SpecifiedTradeSettlementPaymentMeans');
        $w->ram($means, 'TypeCode', $payment->code);

        if ($payment->isDirectDebit()) {
            $w->ram($w->ram($means, 'PayerPartyDebtorFinancialAccount'), 'IBANID', $payment->debtorIban);

            return;
        }

        if (!$payment->isCreditTransfer()) {
            return;
        }

        $account = $w->ram($means, 'PayeePartyCreditorFinancialAccount');
        $w->ram($account, 'IBANID', $payment->payeeIban);

        if ('' !== $payment->payeeName) {
            $w->ram($account, 'AccountName', $payment->payeeName);
        }
    }

    private function terms(DOMElement $settlement, EInvoicePayment $payment): void
    {
        $terms = $this->writer->ram($settlement, 'SpecifiedTradePaymentTerms');
        $this->writer->ram($terms, 'Description', $payment->terms);

        if ($payment->isDirectDebit()) {
            $this->writer->ram($terms, 'DirectDebitMandateID', $payment->mandate);
        }
    }

    private function totals(DOMElement $settlement, EInvoice $invoice): void
    {
        $w = $this->writer;
        $sums = $w->ram($settlement, 'SpecifiedTradeSettlementHeaderMonetarySummation');
        $w->amount($sums, 'LineTotalAmount', $invoice->lineTotal());
        $w->amount($sums, 'TaxBasisTotalAmount', $invoice->lineTotal());
        $w->amount($sums, 'TaxTotalAmount', $invoice->tax(), true);
        $w->amount($sums, 'GrandTotalAmount', $invoice->grandTotal());

        if (null !== $invoice->prepaid) {
            $w->amount($sums, 'TotalPrepaidAmount', $invoice->prepaid);
        }

        $w->amount($sums, 'DuePayableAmount', $invoice->duePayable());
    }
}
