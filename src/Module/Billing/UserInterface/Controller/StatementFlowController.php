<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\CorrectStatement;
use App\Module\Billing\Application\ReleaseStatement;
use App\Module\Billing\Application\StatementPeriod;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementIsIncomplete;
use App\Module\Billing\Domain\StatementIsReleased;
use App\Module\Billing\Domain\StatementIterationIsTaken;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Http\FormInput;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf einer Abrechnung.
 *
 * Jeder Schritt speichert sofort — kein Sitzungsspeicher. Der Preis dafuer
 * ist ein Entwurf in der Liste ab dem ersten Schritt; der Gewinn ist, dass
 * man morgen weitermachen kann.
 */
#[IsGranted(BillingPermissions::EDIT)]
final class StatementFlowController extends AbstractController
{
    public function __construct(
        private readonly StatementRepository $statements,
        private readonly StatementStepInput $input,
        private readonly StatementView $view,
        private readonly ReleaseStatement $release,
        private readonly PropertyDirectory $properties,
        private readonly CorrectStatement $corrections,
        private readonly StatementPeriod $period,
    ) {
    }

    #[Route('/billing/abrechnungen/neu', name: 'app_billing_statement_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->guard($request);
            $statement = $this->started($request);

            if (null !== $statement) {
                return $this->redirectToStep($statement, StatementFlow::ADVANCES);
            }

            $this->addFlash('error', 'billing.error.property_required');
        }

        return $this->render('billing/flow/abrechnung.html.twig', $this->view->start($request));
    }

    #[Route(
        '/billing/abrechnungen/{id}/bearbeiten/{step}',
        name: 'app_billing_statement_edit',
        methods: ['GET', 'POST'],
    )]
    public function edit(string $id, string $step, Request $request): Response
    {
        $statement = $this->draft($id);
        $current = StatementFlow::known($step);

        if ($request->isMethod('POST')) {
            $this->guard($request);
            $errors = $this->input->apply($current, $request, $statement);

            if ([] === $errors) {
                return $this->onwards($statement, $current, $request);
            }
        }

        return $this->render(
            'billing/flow/'.$current.'.html.twig',
            $this->view->of($statement, $current, $request->query->getString('empfaenger')),
        );
    }

    #[Route('/billing/abrechnungen/{id}/freigeben', name: 'app_billing_statement_release', methods: ['POST'])]
    public function releaseIt(string $id, Request $request): Response
    {
        $this->guard($request);
        $statement = $this->draft($id);

        try {
            $this->release->release($statement, new DateTimeImmutable('today'));
            $this->addFlash('success', 'billing.statement.released');

            return $this->redirectToRoute('app_billing_statement_show', ['id' => $statement->id()]);
        } catch (StatementIsIncomplete|StatementIsReleased $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToStep($statement, StatementFlow::PREVIEW);
    }

    /**
     * Eine Korrektur ist ein Klick.
     *
     * Werte und Empfaenger sind bekannt — es gibt nichts auszuwaehlen, also
     * fuehrt der Knopf direkt in die Vorschau.
     *
     * Gibt es schon einen offenen Korrekturentwurf, fuehrt derselbe Knopf
     * dorthin. Zwei offene Korrekturen derselben Abrechnung waeren zwei
     * Antworten auf dieselbe Frage — und die zweite liefe beim Anlegen in den
     * eindeutigen Index.
     */
    #[Route('/billing/abrechnungen/{id}/korrigieren', name: 'app_billing_statement_correct', methods: ['POST'])]
    public function correct(string $id, Request $request): Response
    {
        $this->guard($request);
        $original = $this->statements->byId($id)
            ?? throw new NotFoundHttpException('Diese Abrechnung gibt es nicht.');
        $underway = $this->corrections->openFor($original->number());

        if (null !== $underway) {
            $this->addFlash('info', 'billing.correction.underway');

            return $this->redirectToStep($underway, StatementFlow::PREVIEW);
        }

        try {
            return $this->redirectToStep($this->corrections->of($original), StatementFlow::PREVIEW);
        } catch (StatementIsReleased|StatementIterationIsTaken $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToRoute('app_billing');
    }

    #[Route('/billing/abrechnungen/{id}/loeschen', name: 'app_billing_statement_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $this->guard($request);
        $this->statements->remove($this->draft($id));
        $this->addFlash('success', 'billing.statement.deleted');

        return $this->redirectToRoute('app_billing_statement');
    }

    /** Der erste Schritt legt an: Objekt und Wirtschaftsjahr genuegen. */
    private function started(Request $request): ?Statement
    {
        $number = FormInput::intOrNull($request, 'property');
        $year = FormInput::intOrNull($request, 'fiscalYear');
        $property = null === $number ? null : self::withNumber($this->properties->all(), $number);

        if (null === $property || null === $year) {
            return null;
        }

        $statement = new Statement(
            $this->statements->nextNumber(),
            $property->id,
            $property->number,
            $this->period->of($property->id, $year),
        );
        $this->input->apply(StatementFlow::BASICS, $request, $statement);

        return $statement;
    }

    /**
     * @param list<PropertyBrief> $properties
     */
    private static function withNumber(array $properties, int $number): ?PropertyBrief
    {
        foreach ($properties as $property) {
            if ($property->number === $number) {
                return $property;
            }
        }

        return null;
    }

    private function onwards(Statement $statement, string $step, Request $request): Response
    {
        $back = 'back' === $request->request->getString('direction');
        $target = $back ? StatementFlow::previous($step) : StatementFlow::next($step);

        return $this->redirectToStep($statement, $target ?? $step);
    }

    private function redirectToStep(Statement $statement, string $step): Response
    {
        return $this->redirectToRoute('app_billing_statement_edit', [
            'id' => $statement->id(),
            'step' => $step,
        ]);
    }

    private function draft(string $id): Statement
    {
        $statement = $this->statements->byId($id)
            ?? throw new NotFoundHttpException('Diese Abrechnung gibt es nicht.');

        if (!$statement->isDraft()) {
            throw $this->createAccessDeniedException('Diese Abrechnung ist freigegeben.');
        }

        return $statement;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('billing', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
