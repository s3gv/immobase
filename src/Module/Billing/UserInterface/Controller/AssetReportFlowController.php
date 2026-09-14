<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\CorrectAssetReport;
use App\Module\Billing\Application\ReleaseAssetReport;
use App\Module\Billing\Application\StartAssetReport;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportCannotBeCorrected;
use App\Module\Billing\Domain\AssetReportIsIncomplete;
use App\Module\Billing\Domain\AssetReportIsReleased;
use App\Module\Billing\Domain\AssetReportIterationIsTaken;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\ReportYearIsNotOver;
use App\Shared\Http\FormInput;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf eines Vermoegensberichts.
 *
 * Jeder Schritt speichert sofort — kein Sitzungsspeicher. Der Preis dafuer ist
 * ein Entwurf in der Liste ab dem ersten Schritt; der Gewinn ist, dass man
 * morgen weitermachen kann.
 */
#[IsGranted(BillingPermissions::EDIT)]
final class AssetReportFlowController extends AbstractController
{
    public function __construct(
        private readonly AssetReportRepository $reports,
        private readonly AssetReportStepInput $input,
        private readonly AssetReportView $view,
        private readonly StartAssetReport $start,
        private readonly ReleaseAssetReport $release,
        private readonly CorrectAssetReport $corrections,
    ) {
    }

    #[Route('/billing/vermoegensberichte/neu', name: 'app_billing_report_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->guard($request);

            try {
                $report = $this->start->forProperty(
                    FormInput::intOrNull($request, 'property'),
                    FormInput::intOrNull($request, 'fiscalYear'),
                    $request->request->getString('label'),
                );

                if (null !== $report) {
                    return $this->redirectToStep($report, AssetReportFlow::RESERVE);
                }

                $this->addFlash('error', 'billing.report.error.property_required');
            } catch (ReportYearIsNotOver $problem) {
                $this->addFlash('error', $problem->getMessage());
            }
        }

        return $this->render('billing/report/vermoegensbericht.html.twig', $this->view->start($request));
    }

    #[Route(
        '/billing/vermoegensberichte/{id}/bearbeiten/{step}',
        name: 'app_billing_report_edit',
        methods: ['GET', 'POST'],
    )]
    public function edit(string $id, string $step, Request $request): Response
    {
        $report = $this->open($id);
        $current = AssetReportFlow::known($step);

        if ($request->isMethod('POST')) {
            $this->guard($request);
            $errors = $this->input->apply($current, $request, $report);

            if ([] === $errors) {
                return $this->onwards($report, $current, $request);
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render(
            'billing/report/'.$current.'.html.twig',
            $this->view->of($report, $current),
        );
    }

    #[Route('/billing/vermoegensberichte/{id}/herausgeben', name: 'app_billing_report_release', methods: ['POST'])]
    public function releaseIt(string $id, Request $request): Response
    {
        $this->guard($request);
        $report = $this->open($id);

        try {
            $this->release->release($report, new DateTimeImmutable('today'));
            $this->addFlash('success', 'billing.report.released');

            return $this->redirectToRoute('app_billing_report_show', ['id' => $report->id()]);
        } catch (AssetReportIsIncomplete|AssetReportIsReleased $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToStep($report, AssetReportFlow::RELEASE);
    }

    /**
     * Eine Berichtigung ist ein Klick.
     *
     * Die Positionen sind bekannt — es gibt nichts auszuwaehlen, also fuehrt
     * der Knopf direkt in die Aufstellung. Gibt es schon eine offene
     * Berichtigung, fuehrt derselbe Knopf dorthin.
     */
    #[Route('/billing/vermoegensberichte/{id}/berichtigen', name: 'app_billing_report_correct', methods: ['POST'])]
    public function correct(string $id, Request $request): Response
    {
        $this->guard($request);
        $original = $this->reports->byId($id)
            ?? throw new NotFoundHttpException('Diesen Vermögensbericht gibt es nicht.');
        $underway = $this->corrections->openFor($original->edition()->number());

        // Ist der Bericht selbst der Entwurf, fuehrt kein Weg zu ihm zurueck:
        // ein Entwurf wird bearbeitet und nicht berichtigt. Das sagt die
        // Absage darunter.
        if (null !== $underway && $underway->id() !== $original->id()) {
            $this->addFlash('info', 'billing.report.correction_underway');

            return $this->redirectToStep($underway, AssetReportFlow::ASSETS);
        }

        try {
            return $this->redirectToStep($this->corrections->of($original), AssetReportFlow::ASSETS);
        } catch (AssetReportCannotBeCorrected|AssetReportIterationIsTaken $problem) {
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->redirectToRoute('app_billing_report');
    }

    #[Route('/billing/vermoegensberichte/{id}/loeschen', name: 'app_billing_report_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $this->guard($request);
        $this->reports->remove($this->open($id));
        $this->addFlash('success', 'billing.report.deleted');

        return $this->redirectToRoute('app_billing_report');
    }

    private function onwards(AssetReport $report, string $step, Request $request): Response
    {
        if (AssetReportStepInput::staysHere($request)) {
            return $this->redirectToStep($report, $step);
        }

        $back = 'back' === $request->request->getString('direction');
        $target = $back ? AssetReportFlow::previous($step) : AssetReportFlow::next($step);

        return $this->redirectToStep($report, $target ?? $step);
    }

    private function redirectToStep(AssetReport $report, string $step): Response
    {
        return $this->redirectToRoute('app_billing_report_edit', ['id' => $report->id(), 'step' => $step]);
    }

    /** Ein Bericht, an dem sich noch etwas aendern laesst. */
    private function open(string $id): AssetReport
    {
        $report = $this->reports->byId($id)
            ?? throw new NotFoundHttpException('Diesen Vermögensbericht gibt es nicht.');

        if (!$report->release()->isDraft()) {
            throw $this->createAccessDeniedException('Dieser Vermögensbericht ist herausgegeben.');
        }

        return $report;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('billing', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
