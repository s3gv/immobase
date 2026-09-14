<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Application\FundingSchedules;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetDocument;
use App\Module\Billing\Domain\CostBearing;
use App\Module\Billing\Domain\Resolution;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ein Budgetplan als Brief — vor der Versammlung und danach.
 *
 * Was daraufsteht, verlangt das Beschlussrecht: die Massnahme, die Kosten, der
 * Verteilerschluessel und die Faelligkeit. Bei einer Sonderumlage sind das die
 * vier Angaben, ohne die ein Beschluss anfechtbar ist.
 *
 * Dazu zwei Saetze, die keine Zahl sagt:
 *
 * * **Wer traegt und warum.** Bei einer baulichen Veraenderung folgt das aus
 *   § 21 WEG, und der Empfaenger soll die Norm lesen koennen, nach der er
 *   zahlt.
 * * **Das Nachschussrisiko.** Wird ein Darlehen aufgenommen, verlangt die
 *   Rechtsprechung den Hinweis: faellt ein Eigentuemer aus, haften die
 *   anderen nach.
 *
 * Dieselbe Maschinerie wie bei den anderen Schreiben: FPDF, DIN 5008 Form B.
 */
final readonly class BudgetLetter
{
    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private BudgetTable $table,
        private BudgetShareBlock $share,
    ) {
    }

    public function of(Budget $budget, BudgetDocument $document, DateTimeImmutable $on, bool $isProposal): string
    {
        $sheet = new Sheet($on);
        $sheet->AddPage();

        $recipient = $document->recipient();
        $this->letterhead->head($sheet);
        $this->letterhead->address($sheet, $recipient->label(), $recipient->address());
        $this->letterhead->info($sheet, $document->reference()->toString(), $on->format('d.m.Y'));

        $loan = FundingSchedules::repayment($budget->funding());
        $at = $this->subject($sheet, $budget, $document, $isProposal);
        $at = $this->table->need($sheet, $budget, $at);
        $at = $this->table->funding($sheet, $budget, $loan, $at);
        $at = $this->share->of($sheet, $budget, $document, $at);
        $this->notes($sheet, $budget, $at, $isProposal, null !== $loan);

        return $sheet->bytes();
    }

    private function subject(Sheet $sheet, Budget $budget, BudgetDocument $document, bool $isProposal): float
    {
        $at = 103.0;
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans(
            $isProposal ? 'billing.budget.pdf.subject_proposal' : 'billing.budget.pdf.subject',
            ['%measure%' => $budget->measure()->label()],
        ), 12.0, 'B');
        $sheet->put(Sheet::LEFT, $at + 7.0, \sprintf(
            '%s · %s · %s %d',
            $document->unitLabel(),
            $this->translator->trans($budget->measure()->kind()->labelKey()),
            $this->translator->trans('billing.budget.pdf.from_year'),
            $budget->measure()->firstYear(),
        ), 9.0);

        return $at + 16.0;
    }

    /**
     * Was unter den Zahlen steht.
     *
     * Erst, wer traegt und warum. Dann das Nachschussrisiko, wenn ein Darlehen
     * im Spiel ist. Zuletzt der Beschluss — oder der Hinweis, dass er noch
     * aussteht.
     */
    private function notes(Sheet $sheet, Budget $budget, float $at, bool $isProposal, bool $hasLoan): void
    {
        $bearing = $budget->verdict()->bears() ?? CostBearing::Everyone;
        $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans($bearing->reasonKey()), 8.0) + 3.0;

        if ($hasLoan) {
            $at = $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->translator->trans('billing.budget.pdf.risk'), 8.0) + 3.0;
        }

        $sheet->putLines(Sheet::LEFT, $at, 160.0, $this->closing($budget, $isProposal), 8.0);
    }

    private function closing(Budget $budget, bool $isProposal): string
    {
        if ($isProposal) {
            return $this->translator->trans('billing.budget.pdf.to_be_decided');
        }

        return $budget->resolution()->isRecorded() ? $this->decided($budget->resolution()) : '';
    }

    /**
     * Der Beschlusssatz, aus seinen Teilen.
     *
     * Zusammengesetzt und nicht als ein Satz mit Platzhaltern: Ergebnis und
     * Beschlussnummer sind freiwillig, und „am 14.11.2026.  ." waere ein Satz,
     * dem man die leeren Felder ansieht.
     */
    private function decided(Resolution $resolution): string
    {
        $parts = [$this->translator->trans('billing.budget.pdf.decided', [
            '%day%' => $resolution->decidedOn()?->format('d.m.Y') ?? '',
        ])];

        if ('' !== $resolution->outcome()) {
            $parts[] = $resolution->outcome();
        }

        if ('' !== $resolution->number()) {
            $parts[] = $this->translator->trans('billing.budget.pdf.decision_number', [
                '%number%' => $resolution->number(),
            ]);
        }

        return implode(' · ', $parts);
    }
}
