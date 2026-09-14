<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Application\FundingSchedules;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetDocument;
use App\Shared\Money\Money;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Teil des Briefes, der den Empfaenger betrifft.
 *
 * Vier Zahlen, und nur die, die es gibt: wer nichts anspart, liest keine
 * Zeile ueber das Ansparen. Der **Anteil an der Massnahme** steht oben, weil
 * er die Bezugsgroesse ist; darunter, was daraus jetzt und kuenftig zu zahlen
 * ist.
 *
 * Die Raten der Sonderumlage stehen einzeln da. Der Beschluss nennt die
 * Faelligkeit, und drei Raten sind drei Termine — ein Empfaenger, der nur eine
 * Summe liest, weiss nicht, wann er was ueberweisen soll. Aufgeteilt werden
 * sie von {@see FundingSchedules::instalments()} und nicht hier: eine Rate, die
 * im Brief einen Cent anders steht als in der Vorlage, waere ein Streit.
 */
final readonly class BudgetShareBlock
{
    public function __construct(
        private TranslatorInterface $translator,
        private Amounts $amounts,
    ) {
    }

    public function of(Sheet $sheet, Budget $budget, BudgetDocument $document, float $at): float
    {
        $at = $sheet->heading($at, $this->translator->trans('billing.budget.pdf.your_share'));

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.budget.pdf.share'), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($document->share()), 11.0, 'B');
        $at += 6.5;

        $at = $this->levy($sheet, $budget, $document, $at);
        $at = $this->line($sheet, $at, 'billing.budget.pdf.your_saving', $document->saving());

        return $this->line($sheet, $at, 'billing.budget.pdf.your_rate', $document->loanPayment()) + 6.0;
    }

    /** Die Sonderumlage mit ihren Faelligkeiten. */
    private function levy(Sheet $sheet, Budget $budget, BudgetDocument $document, float $at): float
    {
        if ($document->levy()->isZero()) {
            return $at;
        }

        $at = $this->line($sheet, $at, 'billing.budget.pdf.your_levy', $document->levy());
        $dates = FundingSchedules::levyDates($budget->funding());
        $parts = FundingSchedules::instalments($document->levy(), max(1, \count($dates)));

        foreach ($dates as $index => $due) {
            $at = $this->part($sheet, $at, $due, $parts[$index] ?? Money::zero(), $index + 1);
        }

        return $at;
    }

    private function part(Sheet $sheet, float $at, DateTimeImmutable $due, Money $amount, int $number): float
    {
        $sheet->put(Sheet::LEFT + 2.0, $at, $this->translator->trans('billing.budget.pdf.instalment', [
            '%number%' => $number,
            '%day%' => $due->format('d.m.Y'),
        ]), 8.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($amount), 8.0);

        return $at + 4.5;
    }

    private function line(Sheet $sheet, float $at, string $key, Money $amount): float
    {
        if ($amount->isZero()) {
            return $at;
        }

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($key), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($amount), 9.0);

        return $at + 5.5;
    }
}
