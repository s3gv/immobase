<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\EInvoiceData;
use App\Shared\EInvoice\EInvoicePayment;
use App\Shared\Money\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wie gezahlt wird — in der Sprache einer Rechnung.
 *
 * Eine Rechnung, auf der ein Betrag offen ist, nennt, bis wann oder wie er zu
 * zahlen ist (BR-CO-25). Die Dauermietrechnung nimmt die Abrede aus dem
 * Mietvertrag, die Abrechnung eine feste Frist. Bei Lastschrift steht dazu das
 * Mandat, unter dem eingezogen wird.
 */
final readonly class EInvoiceTerms
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function forRent(EInvoiceData $data, string $payeeIban, string $payeeName): EInvoicePayment
    {
        $due = '' === $data->paymentDue() ? 'third_working_day' : $data->paymentDue();

        return $this->payment($data, $this->german('billing.einvoice.terms.'.$due), $payeeIban, $payeeName, true);
    }

    /**
     * Was aus dem Saldo einer Abrechnung folgt.
     *
     * Eine Nachzahlung hat eine Frist und wird ueberwiesen oder eingezogen.
     * **Ein Guthaben wird nie eingezogen**: es geht an den Mieter zurueck —
     * auf das Konto, von dem er per Lastschrift zahlt, wenn es bekannt ist,
     * und sonst ohne vorgegebenen Weg. Bleibt nichts zu zahlen, gibt es
     * auch keinen.
     *
     * @param Money  $due       der Saldo des Schreibens — bei einer Korrektur die Differenz
     * @param string $buyerName wem ein Guthaben gehoert
     */
    public function forStatement(EInvoiceData $data, string $payeeIban, string $payeeName, Money $due, string $buyerName): EInvoicePayment
    {
        if (!$due->isNegative() && !$due->isZero()) {
            return $this->payment($data, $this->german('billing.result.payable'), $payeeIban, $payeeName, true);
        }

        if ($due->isZero()) {
            return EInvoicePayment::notDefined($this->german('billing.einvoice.terms.nothing_due'));
        }

        $refundTo = $data->isDirectDebit() ? $data->directDebit()['debtorIban'] : '';

        return '' === $refundTo
            ? EInvoicePayment::notDefined($this->german('billing.einvoice.terms.credit'))
            : EInvoicePayment::transfer($this->german('billing.einvoice.terms.credit_to_account'), $refundTo, $buyerName);
    }

    private function payment(EInvoiceData $data, string $terms, string $payeeIban, string $payeeName, bool $collects): EInvoicePayment
    {
        if (!$data->isDirectDebit()) {
            return EInvoicePayment::transfer($terms, $payeeIban, $payeeName);
        }

        $debit = $data->directDebit();

        return EInvoicePayment::directDebit(
            $collects ? $terms.' '.$this->german('billing.einvoice.terms.direct_debit', ['%mandate%' => $debit['mandate']]) : $terms,
            $debit['mandate'],
            $debit['creditorId'],
            $debit['debtorIban'],
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function german(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, null, 'de');
    }
}
