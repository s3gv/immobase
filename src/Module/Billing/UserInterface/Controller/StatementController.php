<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\SurveyAssetReports;
use App\Module\Billing\Application\SurveyBudgets;
use App\Module\Billing\Application\SurveyPlans;
use App\Module\Billing\Application\SurveyRentInvoices;
use App\Module\Billing\Application\SurveyStatements;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementFilter;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Billing\Domain\StatementStatus;
use App\Module\Billing\UserInterface\Twig\CorrectionBadge;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Http\FormInput;
use App\Shared\Ui\Page;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Uebersicht und die Liste der Abrechnungen.
 *
 * Der Menuepunkt fuehrt auf ein kleines Dashboard und nicht auf eine Tabelle:
 * wer hier hereinkommt, will meistens wissen, was noch offen ist — und erst
 * danach, was schon gelaufen ist.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class StatementController extends AbstractController
{
    public function __construct(
        private readonly StatementRepository $statements,
        private readonly SurveyStatements $survey,
        private readonly SurveyPlans $plans,
        private readonly SurveyAssetReports $reports,
        private readonly SurveyBudgets $budgets,
        private readonly SurveyRentInvoices $invoices,
        private readonly BillingPage $page,
        private readonly StatementFlowPage $flow,
        private readonly TranslatorInterface $translator,
        private readonly PropertyDirectory $properties,
        private readonly CorrectionBadge $badge,
    ) {
    }

    #[Route('/billing', name: 'app_billing', methods: ['GET'])]
    public function overview(): Response
    {
        $overview = $this->survey->overview();
        $plans = $this->plans->overview();

        // Die Uebersicht hat gerade gezaehlt — beides — und das Menue muss es
        // nicht noch einmal tun.
        $this->badge->remember(\count($overview['corrections']) + \count($plans['corrections']));

        return $this->render('billing/index.html.twig', [
            ...$overview,
            'plans' => $plans,
            'reports' => $this->reports->overview(),
            'budgets' => $this->budgets->overview(),
            'invoices' => $this->invoices->overview(),
            'trail' => $this->page->trail(),
        ]);
    }

    /**
     * Noch einmal nachsehen, auf Knopfdruck.
     *
     * Die Zahl am Menuepunkt steht sonst so, wie sie bei der Anmeldung oder
     * beim letzten Oeffnen dieser Seite war. Wer gerade nebenan einen Betrag
     * geaendert hat, will nicht bis zur naechsten Anmeldung warten.
     */
    #[Route('/billing/pruefen', name: 'app_billing_recheck', methods: ['POST'])]
    public function recheck(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('billing', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $this->addFlash('info', 0 === $this->badge->refresh()
            ? 'billing.correction.checked_none'
            : 'billing.correction.checked_some');

        return $this->redirectToRoute('app_billing');
    }

    /**
     * Eine freigegebene Abrechnung ansehen.
     *
     * Oeffnen fuehrt hierher und nicht in ein PDF: was drinsteht, sieht man
     * am Bildschirm schneller — und das PDF ist ein anderer Vorgang.
     *
     * Angesehen wird im Rahmen des Ablaufs, auf dem letzten Schritt. Wer
     * gerade noch geprueft hat, findet dieselbe Seite wieder — nur ohne
     * Knoepfe. Zwei Gestalten fuer dieselbe Sache waeren zwei Sachen.
     */
    #[Route(
        '/billing/abrechnungen/{id}',
        name: 'app_billing_statement_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id, Request $request): Response
    {
        $statement = $this->statements->byId($id)
            ?? throw $this->createNotFoundException('Diese Abrechnung gibt es nicht.');
        $documents = $statement->documents();
        $chosen = $request->query->getString('empfaenger');

        return $this->render('billing/statement.html.twig', [
            ...$this->flow->frame($statement, StatementFlow::PREVIEW, editable: false),
            'title' => $this->translator->trans('billing.statement.released_title'),
            'explanation' => $this->translator->trans('billing.statement.released_explanation'),
            'statement' => $statement,
            'documents' => $documents,
            'document' => self::documentOf($documents, $chosen),
            'trail' => $this->page->trail('billing.statement.heading'),
        ]);
    }

    #[Route('/billing/abrechnungen', name: 'app_billing_statement', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filterFrom($request);
        $total = $this->statements->countMatching($filter);
        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $total);

        return $this->render('billing/statements.html.twig', [
            'statements' => $this->statements->matching($filter, $page),
            'total' => $total,
            'page' => $page,
            'filter' => $filter,
            'properties' => $this->properties->all(),
            'years' => $this->statements->years(),
            'statuses' => StatementStatus::cases(),
            'url' => $this->page->listUrl($request),
            'trail' => $this->page->trail('billing.statement.heading'),
        ]);
    }

    private static function filterFrom(Request $request): StatementFilter
    {
        return StatementFilter::of(
            $request->query->getString('objekt'),
            $request->query->getString('jahr'),
            $request->query->getString('zustand'),
            $request->query->getString('q'),
        );
    }

    /**
     * @param list<StatementDocument> $documents
     */
    private static function documentOf(array $documents, string $chosen): ?StatementDocument
    {
        foreach ($documents as $document) {
            if ($document->id() === $chosen) {
                return $document;
            }
        }

        return $documents[0] ?? null;
    }
}
