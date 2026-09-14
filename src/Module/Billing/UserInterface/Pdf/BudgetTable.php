<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Application\FundingSchedules;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetNeed;
use App\Module\Billing\Domain\Funding;
use App\Shared\Money\Money;
use App\Shared\Money\RepaymentPlan;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\Sheet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bedarf und Finanzierung auf einem Blatt.
 *
 * Zwei Bloecke, und der zweite ist der, um den es geht: woher das Geld kommt.
 * Er nennt jeden Weg einzeln, weil jeder eine andere Folge hat — die Ruecklage
 * ist schon da, die Sonderumlage ist morgen faellig, das Ansparen erhoeht das
 * Hausgeld, und das Darlehen kostet Zinsen.
 */
final readonly class BudgetTable
{
    public function __construct(
        private TranslatorInterface $translator,
        private Amounts $amounts,
    ) {
    }

    /** Der Bedarf, Position fuer Position. */
    public function need(Sheet $sheet, Budget $budget, float $at): float
    {
        $at = $this->heading($sheet, 'billing.budget.pdf.need', $at);

        foreach ($budget->positions() as $position) {
            $sheet->put(Sheet::LEFT, $at, $position->label(), 9.0);
            $sheet->put(Sheet::LEFT + 100.0, $at, (string) $position->year(), 8.0);
            $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($position->amount()), 9.0);
            $at += 5.0;

            if ('' !== $position->note()) {
                $at = $sheet->putLines(Sheet::LEFT + 2.0, $at - 0.5, 120.0, $position->note(), 7.5) + 0.5;
            }
        }

        return $this->sum($sheet, $at, 'billing.budget.pdf.need_sum', BudgetNeed::of($budget)) + 4.0;
    }

    /** Woher das Geld kommt. */
    public function funding(Sheet $sheet, Budget $budget, ?RepaymentPlan $loan, float $at): float
    {
        $funding = $budget->funding();
        $at = $this->heading($sheet, 'billing.budget.pdf.funding', $at);
        $at = $this->line($sheet, $at, 'billing.budget.pdf.from_reserve', $funding->reserve());
        $at = $this->line($sheet, $at, 'billing.budget.pdf.levy', $funding->levy(), $this->levyNote($funding));
        $at = $this->line($sheet, $at, 'billing.budget.pdf.saving', $funding->saving(), $this->savingNote($funding));
        $at = $this->line($sheet, $at, 'billing.budget.pdf.loan', $funding->loan(), $this->loanNote($loan));

        return $this->sum($sheet, $at, 'billing.budget.pdf.funding_sum', $funding->total()) + 4.0;
    }

    /** „drei Raten ab 01.03.2027, vierteljährlich" */
    private function levyNote(Funding $funding): string
    {
        $first = $funding->levyDueOn();

        if ($funding->levy()->isZero() || null === $first) {
            return '';
        }

        if (1 === $funding->levyParts()) {
            return $this->translator->trans('billing.budget.pdf.levy_once', ['%day%' => $first->format('d.m.Y')]);
        }

        return $this->translator->trans('billing.budget.pdf.levy_parts', [
            '%count%' => $funding->levyParts(),
            '%day%' => $first->format('d.m.Y'),
        ]);
    }

    private function savingNote(Funding $funding): string
    {
        $perYear = FundingSchedules::savingPerYear($funding);

        if ([] === $perYear) {
            return '';
        }

        return $this->translator->trans('billing.budget.pdf.saving_years', [
            '%count%' => $funding->savingYears(),
            '%from%' => $funding->savingFrom(),
            '%amount%' => $this->amounts->money(reset($perYear)),
        ]);
    }

    private function loanNote(?RepaymentPlan $loan): string
    {
        if (null === $loan) {
            return '';
        }

        return $this->translator->trans('billing.budget.pdf.loan_terms', [
            '%payment%' => $this->amounts->money($loan->payment),
            '%months%' => $loan->months(),
            '%rate%' => $this->amounts->number(self::percent($loan->rateBps)),
            '%interest%' => $this->amounts->money($loan->totalInterest()),
        ]);
    }

    private function line(Sheet $sheet, float $at, string $key, Money $amount, string $note = ''): float
    {
        if ($amount->isZero()) {
            return $at;
        }

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($key), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($amount), 9.0);

        if ('' === $note) {
            return $at + 5.0;
        }

        return $sheet->putLines(Sheet::LEFT + 2.0, $at + 4.0, 120.0, $note, 7.5) + 0.5;
    }

    private function heading(Sheet $sheet, string $key, float $at): float
    {
        return $sheet->heading($at, $this->translator->trans($key));
    }

    private function sum(Sheet $sheet, float $at, string $key, Money $amount): float
    {
        $sheet->rule($at);
        $sheet->put(Sheet::LEFT, $at + 1.0, $this->translator->trans($key), 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 1.0, $this->amounts->money($amount), 9.0, 'B');

        return $at + 8.0;
    }

    /** Basispunkte als Prozentsatz: 420 wird zu „4.20". */
    private static function percent(int $rateBps): string
    {
        return \sprintf('%d.%02d', intdiv($rateBps, 100), $rateBps % 100);
    }
}
