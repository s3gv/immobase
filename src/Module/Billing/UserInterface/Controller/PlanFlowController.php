<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\CorrectPlan;
use App\Module\Billing\Application\ProposePlan;
use App\Module\Billing\Application\ReleasePlan;
use App\Module\Billing\Application\StartPlan;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanHasDrifted;
use App\Module\Billing\Domain\PlanIsIncomplete;
use App\Module\Billing\Domain\PlanIsReleased;
use App\Module\Billing\Domain\PlanIterationIsTaken;
use App\Module\Billing\Domain\PlanRepository;
use App\Shared\Http\FormInput;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf eines Wirtschaftsplans.
 *
 * Jeder Schritt speichert sofort — kein Sitzungsspeicher. Der Preis dafuer ist
 * ein Entwurf in der Liste ab dem ersten Schritt; der Gewinn ist, dass man
 * morgen weitermachen kann.
 */
#[IsGranted(BillingPermissions::EDIT)]
final class PlanFlowController extends AbstractController
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly PlanStepInput $input,
        private readonly PlanView $view,
        private readonly ReleasePlan $release,
        private readonly CorrectPlan $corrections,
        private readonly StartPlan $start,
        private readonly ProposePlan $proposal,
    ) {
    }

    #[Route('/billing/wirtschaftsplaene/neu', name: 'app_billing_plan_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->guard($request);
            $plan = $this->start->forProperty(
                FormInput::intOrNull($request, 'property'),
                FormInput::intOrNull($request, 'fiscalYear'),
                $request->request->getString('label'),
            );

            if (null !== $plan) {
                return $this->redirectToStep($plan, PlanFlow::POSITIONS);
            }

            $this->addFlash('error', 'billing.plan.error.property_required');
        }

        return $this->render('billing/plan/wirtschaftsplan.html.twig', $this->view->start($request));
    }

    #[Route(
        '/billing/wirtschaftsplaene/{id}/bearbeiten/{step}',
        name: 'app_billing_plan_edit',
        methods: ['GET', 'POST'],
    )]
    public function edit(string $id, string $step, Request $request): Response
    {
        $plan = $this->open($id);
        $current = PlanFlow::known($step);

        if ($request->isMethod('POST')) {
            $this->guard($request);
            $errors = $this->input->apply($current, $request, $plan);

            if ([] === $errors) {
                return $this->onwards($plan, $current, $request);
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render(
            'billing/plan/'.$current.'.html.twig',
            $this->view->of($plan, $current, $request->query->getString('einheit')),
        );
    }

    /**
     * Die Beschlussvorlage herausgeben.
     *
     * Der Plan aendert sich dadurch nicht — er bekommt nur einen Tag, an dem
     * er den Eigentuemern vorlag. Aendern darf man ihn danach weiter: die
     * Versammlung beschliesst am Ende, und was sie anders sieht, gehoert in
     * den Plan, nicht daneben.
     */
    #[Route('/billing/wirtschaftsplaene/{id}/vorlegen', name: 'app_billing_plan_propose', methods: ['POST'])]
    public function propose(string $id, Request $request): Response
    {
        $this->guard($request);
        $plan = $this->open($id);

        try {
            $this->proposal->propose($plan, new DateTimeImmutable('today'));
            $this->addFlash('success', 'billing.plan.proposed');
        } catch (PlanIsIncomplete|PlanIsReleased $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToStep($plan, PlanFlow::PROPOSAL);
    }

    #[Route('/billing/wirtschaftsplaene/{id}/freigeben', name: 'app_billing_plan_release', methods: ['POST'])]
    public function releaseIt(string $id, Request $request): Response
    {
        $this->guard($request);
        $plan = $this->open($id);

        try {
            $this->release->release(
                $plan,
                new DateTimeImmutable('today'),
                despiteDrift: $request->request->getBoolean('despiteDrift'),
            );
            $this->addFlash('success', 'billing.plan.released');

            return $this->redirectToRoute('app_billing_plan_show', ['id' => $plan->id()]);
        } catch (PlanHasDrifted|PlanIsIncomplete|PlanIsReleased $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToStep($plan, PlanFlow::DECISION);
    }

    /**
     * Eine Korrektur ist ein Klick.
     *
     * Die Zeilen sind bekannt — es gibt nichts auszuwaehlen, also fuehrt der
     * Knopf direkt in die Vorschau. Gibt es schon einen offenen
     * Korrekturentwurf, fuehrt derselbe Knopf dorthin.
     */
    #[Route('/billing/wirtschaftsplaene/{id}/korrigieren', name: 'app_billing_plan_correct', methods: ['POST'])]
    public function correct(string $id, Request $request): Response
    {
        $this->guard($request);
        $original = $this->plans->byId($id)
            ?? throw new NotFoundHttpException('Diesen Wirtschaftsplan gibt es nicht.');
        $underway = $this->corrections->openFor($original->edition()->number());

        if (null !== $underway) {
            $this->addFlash('info', 'billing.plan.correction_underway');

            return $this->redirectToStep($underway, PlanFlow::DECISION);
        }

        try {
            return $this->redirectToStep($this->corrections->of($original), PlanFlow::DECISION);
        } catch (PlanIsReleased|PlanIterationIsTaken $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToRoute('app_billing_plan');
    }

    #[Route('/billing/wirtschaftsplaene/{id}/loeschen', name: 'app_billing_plan_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $this->guard($request);
        $this->plans->remove($this->open($id));
        $this->addFlash('success', 'billing.plan.deleted');

        return $this->redirectToRoute('app_billing_plan');
    }

    private function onwards(Plan $plan, string $step, Request $request): Response
    {
        if (PlanStepInput::staysHere($request)) {
            return $this->redirectToStep($plan, $step);
        }

        $back = 'back' === $request->request->getString('direction');
        $target = $back ? PlanFlow::previous($step) : PlanFlow::next($step);

        return $this->redirectToStep($plan, $target ?? $step);
    }

    private function redirectToStep(Plan $plan, string $step): Response
    {
        return $this->redirectToRoute('app_billing_plan_edit', ['id' => $plan->id(), 'step' => $step]);
    }

    /**
     * Ein Plan, an dem sich noch etwas aendern laesst.
     *
     * Das ist der Entwurf **und** die herausgegebene Vorlage: bis die
     * Versammlung beschlossen hat, sind die Zahlen ein Vorschlag. Erst der
     * Beschluss friert sie ein.
     */
    private function open(string $id): Plan
    {
        $plan = $this->plans->byId($id)
            ?? throw new NotFoundHttpException('Diesen Wirtschaftsplan gibt es nicht.');

        if (!$plan->stage()->isOpen()) {
            throw $this->createAccessDeniedException('Dieser Wirtschaftsplan ist beschlossen.');
        }

        return $plan;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('billing', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
