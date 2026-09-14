<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\Loan;
use App\Module\Property\Contract\PropertyDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf: ein Darlehen anlegen und bearbeiten.
 *
 * Wie ueberall speichert jeder Schritt sofort. Der zweite ist der, an dem es
 * schiefgehen kann: eine Rate, die den Zins nicht deckt, ergibt keinen
 * Tilgungsplan — dann bleibt der alte Stand stehen und die Absage steht im
 * Formular.
 */
#[IsGranted(FinancePermissions::EDIT)]
final class LoanFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireLoan $loan,
        private readonly LoanStepInput $input,
        private readonly LoanFlowPage $page,
        private readonly PropertyDirectory $properties,
    ) {
    }

    #[Route('/finanzen/darlehen/neu', name: 'app_finance_loan_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->show(null, LoanFlow::BASICS, $request);
        }

        $this->guard($request);
        $created = $this->input->create($request);

        if (null === $created['loan']) {
            return $this->show(null, LoanFlow::BASICS, $request, $created['errors']);
        }

        $this->addFlash('success', 'finance.loan.created');

        return $this->toStep($created['loan'], LoanFlow::TERMS);
    }

    #[Route(
        '/finanzen/darlehen/{number}/bearbeiten/{step}',
        name: 'app_finance_loan_edit',
        requirements: ['number' => '\d+', 'step' => '[a-z]+'],
        defaults: ['step' => LoanFlow::BASICS],
        methods: ['GET', 'POST'],
    )]
    public function edit(int $number, string $step, Request $request): Response
    {
        $loan = ($this->loan)($number);
        $current = LoanFlow::known($step);

        if (!$request->isMethod('POST')) {
            return $this->show($loan, $current, $request);
        }

        $this->guard($request);
        $errors = $this->input->apply($current, $request, $loan);

        if ([] !== $errors) {
            return $this->show($loan, $current, $request, $errors);
        }

        return $this->onwards($loan, $current, $request);
    }

    /** Weiter, zurueck — oder fertig. */
    private function onwards(Loan $loan, string $step, Request $request): Response
    {
        if ('back' === $request->request->getString('direction')) {
            return $this->toStep($loan, LoanFlow::previous($step) ?? LoanFlow::BASICS);
        }

        $next = LoanFlow::next($step);

        if (null !== $next) {
            return $this->toStep($loan, $next);
        }

        $this->addFlash('success', 'finance.loan.saved');

        return $this->redirectToRoute('app_finance_loan_show', ['number' => $loan->number()]);
    }

    private function toStep(Loan $loan, string $step): Response
    {
        return $this->redirectToRoute('app_finance_loan_edit', ['number' => $loan->number(), 'step' => $step]);
    }

    /**
     * Nach einem Fehler steht das Abgeschickte im Formular und nicht der
     * gespeicherte Stand: wer sich vertippt hat, will die Stelle verbessern.
     *
     * @param array<string, string> $errors
     */
    private function show(?Loan $loan, string $step, Request $request, array $errors = []): Response
    {
        return $this->render('finance/loan/steps/'.$step.'.html.twig', [
            ...$this->page->parameters($loan, $step, $errors),
            'submitted' => [] === $errors ? [] : $request->request->all(),
            'properties' => $this->properties->all(),
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('finance_loan_flow', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
