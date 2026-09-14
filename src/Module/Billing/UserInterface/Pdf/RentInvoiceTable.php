<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\ProposedInvoice;
use App\Shared\Money\Money;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\PostalLines;
use App\Shared\Pdf\Sheet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aussteller und Betraege auf dem Blatt.
 *
 * Zwei Bloecke. Oben, wer die Rechnung stellt — **der Vermieter mit seiner
 * Steuernummer**, nicht die Verwaltung im Briefkopf. Darunter, was monatlich
 * faellig wird: die Positionen einzeln, weil der Mieter sie einzeln im
 * Vertrag wiederfindet, und darunter Netto, Steuer und Brutto.
 *
 * Ohne Umsatzsteuer entfallen die beiden Zeilen dazwischen. Eine Zeile
 * „Umsatzsteuer 0,00 €" auf einer steuerfreien Vermietung waere ein
 * Steuerausweis ueber nichts — und § 14c kennt keinen Unterschied zwischen
 * „ausgewiesen" und „ausgewiesen, aber null".
 */
final readonly class RentInvoiceTable
{
    public function __construct(
        private TranslatorInterface $translator,
        private Amounts $amounts,
    ) {
    }

    public function of(Sheet $sheet, ProposedInvoice $body, float $at): float
    {
        $at = $this->issuer($sheet, $body, $at);

        return $this->amountsOf($sheet, $body, $at + 4.0);
    }

    private function issuer(Sheet $sheet, ProposedInvoice $body, float $at): float
    {
        $at = $this->heading($sheet, 'billing.invoice.pdf.issuer', $at);
        $at = $this->row($sheet, $at, $this->translator->trans('billing.invoice.landlord'), $body->landlordName);
        // In einer Zeile: hier steht der Aussteller im Text und nicht im
        // Anschriftfeld.
        $at = $this->row(
            $sheet,
            $at,
            $this->translator->trans('billing.invoice.landlord_address'),
            PostalLines::fromText($body->landlordAddress)->inline(),
        );

        return $this->row($sheet, $at, $this->translator->trans('billing.invoice.tax_number'), $body->landlordTaxNumber);
    }

    private function amountsOf(Sheet $sheet, ProposedInvoice $body, float $at): float
    {
        $at = $this->heading($sheet, 'billing.invoice.pdf.monthly', $at);

        foreach ($body->lines() as $line) {
            $at = $this->money($sheet, $at, $this->translator->trans('billing.invoice.line.'.$line['key']), $line['amount']);
        }

        if ($body->taxation->isCharged()) {
            $at = $this->money($sheet, $at, $this->translator->trans('billing.invoice.net'), $body->net());
            $at = $this->money($sheet, $at, $this->translator->trans('billing.invoice.vat', [
                '%rate%' => $this->percent($body->taxation->rateBps()),
            ]), $body->tax());
        }

        return $this->sum($sheet, $at, 'billing.invoice.gross', $body->gross()) + 4.0;
    }

    /** 1900 wird zu „19,00 %" — dieselbe Umrechnung wie der Twig-Filter. */
    private function percent(int $basisPoints): string
    {
        return $this->amounts->number(\sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100)).' %';
    }

    private function heading(Sheet $sheet, string $key, float $at): float
    {
        return $sheet->heading($at, $this->translator->trans($key));
    }

    private function row(Sheet $sheet, float $at, string $label, string $value): float
    {
        $sheet->put(Sheet::LEFT, $at, $label, 9.0);
        $sheet->put(Sheet::LEFT + 55.0, $at, '' === $value ? '—' : $value, 9.0);

        return $at + 5.0;
    }

    private function money(Sheet $sheet, float $at, string $label, Money $amount): float
    {
        $sheet->put(Sheet::LEFT, $at, $label, 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($amount), 9.0);

        return $at + 5.0;
    }

    private function sum(Sheet $sheet, float $at, string $label, Money $amount): float
    {
        $sheet->rule($at);
        $sheet->put(Sheet::LEFT, $at + 1.0, $this->translator->trans($label), 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 1.0, $this->amounts->money($amount), 9.0, 'B');

        return $at + 7.0;
    }
}
