<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\Loan;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was ein Schritt des Darlehens zum Zeichnen braucht.
 *
 * Dieselbe Gestalt wie bei der Kostenposition: die Schrittliste traegt
 * Adressen und keine Knoepfe, denn jeder Schritt hat schon gespeichert.
 */
final readonly class LoanFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    public function parameters(?Loan $loan, string $step, array $errors): array
    {
        $heading = null === $loan ? 'finance.loan.new.heading' : 'finance.loan.edit.heading';

        return [
            'loan' => $loan,
            'step' => $step,
            // Der Zinssatz steht in Basispunkten und gehoert als Dezimalzahl
            // ins Feld — sonst tippt jemand 420 und meint 4,20.
            'rate' => null === $loan ? '' : LoanView::rate($loan->terms()->rateBps()),
            'errors' => $errors,
            'action' => $this->actionFor($loan, $step),
            'sections' => $this->steps($loan),
            'current' => $step,
            'heading' => null === $loan
                ? $this->translator->trans($heading)
                : $this->translator->trans('finance.loan.number', ['%number%' => $loan->number()]),
            'subheading' => null === $loan ? $this->translator->trans('finance.loan.new.explanation') : null,
            'title' => $this->translator->trans('finance.loan.section.'.LoanFlow::name($step)),
            'explanation' => $this->translator->trans('finance.loan.explanation.'.LoanFlow::name($step)),
            ...$this->place($loan, $step),
            'trail' => $this->trail($heading),
        ];
    }

    /**
     * Der Pfad bis hierher.
     *
     * @return list<array{label: string, url: string|null}>
     */
    private function trail(string $heading): array
    {
        return [
            ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
            [
                'label' => $this->translator->trans('finance.loan.heading'),
                'url' => $this->urls->generate('app_finance_loan'),
            ],
            ['label' => $this->translator->trans($heading), 'url' => null],
        ];
    }

    /**
     * Wo im Ablauf man steht und wohin es von hier geht.
     *
     * @return array<string, mixed>
     */
    private function place(?Loan $loan, string $step): array
    {
        return [
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => LoanFlow::positionOf($step),
                '%count%' => LoanFlow::count(),
            ]),
            'isLast' => null !== $loan && null === LoanFlow::next($step),
            'hasPrevious' => null !== $loan && null !== LoanFlow::previous($step),
            'cancel' => null === $loan
                ? $this->urls->generate('app_finance_loan')
                : $this->urls->generate('app_finance_loan_show', ['number' => $loan->number()]),
        ];
    }

    private function actionFor(?Loan $loan, string $step): string
    {
        return null === $loan
            ? $this->urls->generate('app_finance_loan_new')
            : $this->urls->generate('app_finance_loan_edit', ['number' => $loan->number(), 'step' => $step]);
    }

    /**
     * @return list<array{key: string, label: string, url: string|null}>
     */
    private function steps(?Loan $loan): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translator->trans('finance.loan.section.'.LoanFlow::name($key)),
            'url' => null === $loan
                ? null
                : $this->urls->generate('app_finance_loan_edit', ['number' => $loan->number(), 'step' => $key]),
        ], LoanFlow::keys());
    }
}
