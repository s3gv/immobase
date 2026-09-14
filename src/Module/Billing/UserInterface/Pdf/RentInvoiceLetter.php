<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\ProposedInvoice;
use App\Module\Billing\Domain\RentInvoice;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Dauermietrechnung als Brief.
 *
 * Was daraufsteht, verlangt § 14 Abs. 4 UStG. Jede Angabe daraus ist hier zu
 * finden: Aussteller mit Anschrift und Steuernummer, Empfaenger, Datum,
 * Rechnungsnummer, Art und Umfang der Leistung, Leistungszeitraum, Entgelt,
 * Steuersatz und Steuerbetrag.
 *
 * **Der Aussteller ist der Vermieter, nicht die Verwaltung.** Der Briefkopf
 * traegt die Organisationsangaben, weil das Schreiben von hier kommt — aber
 * die Rechnung stellt der Eigentuemer, und darum steht er im Blatt noch
 * einmal, mit seiner Steuernummer.
 *
 * Am Ende eine Unterschriftszeile: das Schreiben wird Bestandteil des
 * Mietvertrags, und wir versenden nicht selbst. Wer es herunterlaedt,
 * unterschreibt es.
 *
 * Dieselbe Maschinerie wie bei den anderen Schreiben: FPDF, DIN 5008 Form B.
 */
final readonly class RentInvoiceLetter
{
    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private RentInvoiceTable $table,
    ) {
    }

    public function of(RentInvoice $invoice, ProposedInvoice $body, DateTimeImmutable $on): string
    {
        $sheet = new Sheet($on);
        $sheet->AddPage();

        $this->letterhead->head($sheet);
        $this->letterhead->address($sheet, $body->tenantName, $body->tenantAddress);
        $this->letterhead->info($sheet, $invoice->reference(), $on->format('d.m.Y'));

        $at = $this->subject($sheet, $body);
        $at = $this->table->of($sheet, $body, $at);
        $this->notes($sheet, $body, $at);

        return $sheet->bytes();
    }

    /**
     * Betreff, Leistungsgegenstand und Zeitraum.
     *
     * Der Zeitraum steht hier und nicht in der Tabelle: er ist eine Angabe
     * ueber die Leistung und keine ueber das Geld.
     */
    private function subject(Sheet $sheet, ProposedInvoice $body): float
    {
        $at = 103.0;
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.invoice.pdf.subject'), 12.0, 'B');
        $sheet->put(Sheet::LEFT, $at + 7.0, $body->letLabel, 9.0);
        $sheet->put(Sheet::LEFT, $at + 12.0, $this->periodOf($body), 9.0);

        return $at + 20.0;
    }

    private function periodOf(ProposedInvoice $body): string
    {
        $validity = $body->validity;
        $until = $validity->until();

        if (null === $until) {
            return $this->translator->trans('billing.invoice.period_open', [
                '%from%' => $validity->from()->format('d.m.Y'),
            ]);
        }

        return $this->translator->trans('billing.invoice.period_closed', [
            '%from%' => $validity->from()->format('d.m.Y'),
            '%until%' => $until->format('d.m.Y'),
        ]);
    }

    /**
     * Was unter der Aufstellung steht.
     *
     * Die beiden Bestaetigungssaetze nur bei Option: ohne sie waeren sie eine
     * Behauptung ueber einen Verzicht, den niemand erklaert hat. Danach der
     * Zahlungsempfaenger, der Satz ueber die Geltung — und die Zeile, auf der
     * unterschrieben wird.
     */
    private function notes(Sheet $sheet, ProposedInvoice $body, float $at): void
    {
        if ($body->taxation->isCharged()) {
            $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('billing.invoice.pdf.option'), 8.0) + 2.0;
            $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('billing.invoice.pdf.confirmation'), 8.0) + 4.0;
        }

        $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('billing.invoice.pdf.payee', [
            '%name%' => $body->payeeName,
            '%iban%' => $body->payeeIban,
        ]), 9.0) + 4.0;

        $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('billing.invoice.pdf.part_of_contract'), 8.0) + 2.0;
        $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('billing.invoice.pdf.until_changed'), 8.0) + 12.0;

        $this->signature($sheet, $at);
    }

    /** Ort, Datum, Unterschrift des Vermieters — von Hand. */
    private function signature(Sheet $sheet, float $at): void
    {
        $at = $sheet->room($at, 20.0);
        $sheet->rule($at);
        $sheet->put(Sheet::LEFT, $at + 1.5, $this->translator->trans('billing.invoice.pdf.signature'), 7.5);
    }
}
