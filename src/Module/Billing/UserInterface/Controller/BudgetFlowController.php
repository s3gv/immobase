<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\CorrectBudget;
use App\Module\Billing\Application\ProposeBudget;
use App\Module\Billing\Application\ReleaseBudget;
use App\Module\Billing\Application\StartBudget;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetCannotBeCorrected;
use App\Module\Billing\Domain\BudgetIsDecided;
use App\Module\Billing\Domain\BudgetIsIncomplete;
use App\Module\Billing\Domain\BudgetIterationIsTaken;
use App\Module\Billing\Domain\BudgetRepository;
use App\Shared\Http\FormInput;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf eines Budgetplans.
 *
 * Jeder Schritt speichert sofort — kein Sitzungsspeicher. Der Preis dafuer ist
 * ein Entwurf in der Liste ab dem ersten Schritt; der Gewinn ist, dass man
 * morgen weitermachen kann.
 */
#[IsGranted(BillingPermissions::EDIT)]
final class BudgetFlowController extends AbstractController
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly BudgetStepInput $input,
        private readonly BudgetView $view,
        private readonly StartBudget $start,
        private readonly ProposeBudget $proposal,
        private readonly ReleaseBudget $release,
        private readonly CorrectBudget $corrections,
    ) {
    }

    #[Route('/billing/budgetplaene/neu', name: 'app_billing_budget_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->guard($request);
            $budget = $this->start->forProperty(
                FormInput::intOrNull($request, 'property'),
                FormInput::intOrNull($request, 'firstYear'),
                $request->request->getString('label'),
                $request->request->getString('kind'),
            );

            if (null !== $budget) {
                return $this->redirectToStep($budget, BudgetFlow::COSTS);
            }

            $this->addFlash('error', 'billing.budget.error.property_required');
        }

        return $this->render('billing/budget/massnahme.html.twig', $this->view->start($request));
    }

    #[Route(
        '/billing/budgetplaene/{id}/bearbeiten/{step}',
        name: 'app_billing_budget_edit',
        methods: ['GET', 'POST'],
    )]
    public function edit(string $id, string $step, Request $request): Response
    {
        $budget = $this->open($id);
        $current = BudgetFlow::known($step);

        if ($request->isMethod('POST')) {
            $this->guard($request);
            $errors = $this->input->apply($current, $request, $budget);

            if ([] === $errors) {
                return $this->onwards($budget, $current, $request);
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('billing/budget/'.$current.'.html.twig', $this->view->of($budget, $current));
    }

    /**
     * Die Beschlussvorlage herausgeben.
     *
     * Der Plan aendert sich dadurch nicht — er bekommt einen Tag, an dem er
     * den Eigentuemern vorlag. Aendern darf man ihn danach weiter; die
     * Versammlung beschliesst am Ende.
     */
    #[Route('/billing/budgetplaene/{id}/vorlegen', name: 'app_billing_budget_propose', methods: ['POST'])]
    public function propose(string $id, Request $request): Response
    {
        $this->guard($request);
        $budget = $this->open($id);

        try {
            $this->proposal->propose($budget, new DateTimeImmutable('today'));
            $this->addFlash('success', 'billing.budget.proposed');
        } catch (BudgetIsDecided|BudgetIsIncomplete $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToStep($budget, BudgetFlow::SHARING);
    }

    #[Route('/billing/budgetplaene/{id}/beschliessen', name: 'app_billing_budget_release', methods: ['POST'])]
    public function releaseIt(string $id, Request $request): Response
    {
        $this->guard($request);
        $budget = $this->open($id);

        try {
            $this->release->release($budget, new DateTimeImmutable('today'));
            $this->addFlash('success', 'billing.budget.released');

            return $this->redirectToRoute('app_billing_budget_show', ['id' => $budget->id()]);
        } catch (BudgetIsDecided|BudgetIsIncomplete $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToStep($budget, BudgetFlow::DECISION);
    }

    /** Eine Berichtigung ist ein Klick. */
    #[Route('/billing/budgetplaene/{id}/berichtigen', name: 'app_billing_budget_correct', methods: ['POST'])]
    public function correct(string $id, Request $request): Response
    {
        $this->guard($request);
        $original = $this->budgets->byId($id)
            ?? throw new NotFoundHttpException('Diesen Budgetplan gibt es nicht.');
        $underway = $this->corrections->openFor($original->edition()->number());

        if (null !== $underway && $underway->id() !== $original->id()) {
            $this->addFlash('info', 'billing.budget.correction_underway');

            return $this->redirectToStep($underway, BudgetFlow::COSTS);
        }

        try {
            return $this->redirectToStep($this->corrections->of($original), BudgetFlow::COSTS);
        } catch (BudgetCannotBeCorrected|BudgetIterationIsTaken $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToRoute('app_billing_budget');
    }

    #[Route('/billing/budgetplaene/{id}/loeschen', name: 'app_billing_budget_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $this->guard($request);
        $this->budgets->remove($this->open($id));
        $this->addFlash('success', 'billing.budget.deleted');

        return $this->redirectToRoute('app_billing_budget');
    }

    private function onwards(Budget $budget, string $step, Request $request): Response
    {
        if (BudgetStepInput::staysHere($request)) {
            return $this->redirectToStep($budget, $step);
        }

        $back = 'back' === $request->request->getString('direction');
        $target = $back ? BudgetFlow::previous($step) : BudgetFlow::next($step);

        return $this->redirectToStep($budget, $target ?? $step);
    }

    private function redirectToStep(Budget $budget, string $step): Response
    {
        return $this->redirectToRoute('app_billing_budget_edit', ['id' => $budget->id(), 'step' => $step]);
    }

    /**
     * Ein Plan, an dem sich noch etwas aendern laesst.
     *
     * Das ist der Entwurf **und** die herausgegebene Vorlage: bis die
     * Versammlung beschlossen hat, sind die Zahlen ein Vorschlag.
     */
    private function open(string $id): Budget
    {
        $budget = $this->budgets->byId($id)
            ?? throw new NotFoundHttpException('Diesen Budgetplan gibt es nicht.');

        if (!$budget->stage()->isOpen()) {
            throw $this->createAccessDeniedException('Dieser Budgetplan ist beschlossen.');
        }

        return $budget;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('billing', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
