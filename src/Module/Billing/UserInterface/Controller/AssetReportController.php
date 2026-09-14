<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeAssetReport;
use App\Module\Billing\Application\CorrectAssetReport;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\AssetReportFilter;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\StatementStatus;
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
 * Die Liste der Vermoegensberichte und der Blick in einen herausgegebenen.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class AssetReportController extends AbstractController
{
    public function __construct(
        private readonly AssetReportRepository $reports,
        private readonly ComposeAssetReport $compose,
        private readonly CorrectAssetReport $corrections,
        private readonly BillingPage $page,
        private readonly AssetReportFlowPage $flow,
        private readonly PropertyDirectory $properties,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/billing/vermoegensberichte', name: 'app_billing_report', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filterFrom($request);
        $total = $this->reports->countMatching($filter);
        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $total);

        return $this->render('billing/reports.html.twig', [
            'reports' => $this->reports->matching($filter, $page),
            'total' => $total,
            'page' => $page,
            'filter' => $filter,
            'properties' => $this->properties->all(),
            'years' => $this->reports->years(),
            'statuses' => StatementStatus::cases(),
            'url' => $this->listUrl($request),
            'trail' => $this->page->trail('billing.report.heading'),
        ]);
    }

    /**
     * Einen herausgegebenen Bericht ansehen.
     *
     * Im Rahmen des Ablaufs, auf dem letzten Schritt — wer gerade noch geprueft
     * hat, findet dieselbe Seite wieder, nur ohne Knoepfe.
     *
     * **Nur einen herausgegebenen.** Auf dieser Seite stehen Kontostaende, der
     * Ruecklagenstand und die offenen Hausgelder; bei einem Entwurf waeren sie
     * frisch gerechnet und noch von niemandem herausgegeben. Das Leserecht ist
     * das weiteste im Modul — was es sieht, sieht jeder im Haus. Die Liste
     * verlinkt Entwuerfe nicht, aber eine Adresse laesst sich tippen.
     */
    #[Route(
        '/billing/vermoegensberichte/{id}',
        name: 'app_billing_report_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): Response
    {
        $report = $this->reports->byId($id)
            ?? throw $this->createNotFoundException('Diesen Vermögensbericht gibt es nicht.');

        if ($report->release()->isDraft()) {
            throw $this->createNotFoundException('Ein Entwurf wird im Ablauf bearbeitet und nicht angesehen.');
        }

        $body = $this->compose->of($report);

        return $this->render('billing/report.html.twig', [
            ...$this->flow->frame($report, AssetReportFlow::RELEASE, editable: false),
            'title' => $this->translator->trans('billing.report.released_title'),
            'explanation' => $this->translator->trans('billing.report.released_explanation'),
            'report' => $report,
            'body' => $body,
            'banks' => $body->of(AssetKind::Bank),
            'liabilities' => $body->of(AssetKind::Liability),
            'holdings' => $body->of(AssetKind::Holding),
            'correctable' => $this->corrections->canBeCorrected($report),
            'trail' => $this->page->trail('billing.report.heading'),
        ]);
    }

    /**
     * Die Adresse der Liste mit Platzhalter fuer die Seitenzahl.
     *
     * Die Filter kommen mit: wer auf Seite zwei blaettert, will dieselbe Liste
     * sehen und nicht wieder alle.
     */
    private function listUrl(Request $request): string
    {
        $parameters = [];

        foreach (['objekt', 'jahr', 'zustand', 'q'] as $name) {
            $value = trim($request->query->getString($name));

            if ('' !== $value) {
                $parameters[$name] = $value;
            }
        }

        return $this->generateUrl('app_billing_report', [...$parameters, 'page' => '__PAGE__']);
    }

    private static function filterFrom(Request $request): AssetReportFilter
    {
        return AssetReportFilter::of(
            $request->query->getString('objekt'),
            $request->query->getString('jahr'),
            $request->query->getString('zustand'),
            $request->query->getString('q'),
        );
    }
}
