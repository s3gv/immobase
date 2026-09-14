<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\PlanDocument;
use App\Module\Billing\Domain\PlanFilter;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\ResolutionStatus;
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
 * Die Liste der Wirtschaftsplaene und der Blick in einen freigegebenen.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class PlanController extends AbstractController
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly BillingPage $page,
        private readonly PlanFlowPage $flow,
        private readonly PropertyDirectory $properties,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/billing/wirtschaftsplaene', name: 'app_billing_plan', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filterFrom($request);
        $total = $this->plans->countMatching($filter);
        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $total);

        return $this->render('billing/plans.html.twig', [
            'plans' => $this->plans->matching($filter, $page),
            'total' => $total,
            'page' => $page,
            'filter' => $filter,
            'properties' => $this->properties->all(),
            'years' => $this->plans->years(),
            'statuses' => ResolutionStatus::cases(),
            'url' => $this->listUrl($request),
            'trail' => $this->page->trail('billing.plan.heading'),
        ]);
    }

    /**
     * Einen freigegebenen Plan ansehen.
     *
     * Im Rahmen des Ablaufs, auf dem letzten Schritt — wer gerade noch geprueft
     * hat, findet dieselbe Seite wieder, nur ohne Knoepfe. Zwei Gestalten fuer
     * dieselbe Sache waeren zwei Sachen.
     */
    #[Route(
        '/billing/wirtschaftsplaene/{id}',
        name: 'app_billing_plan_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id, Request $request): Response
    {
        $plan = $this->plans->byId($id)
            ?? throw $this->createNotFoundException('Diesen Wirtschaftsplan gibt es nicht.');
        $documents = $plan->documents();

        return $this->render('billing/plan.html.twig', [
            ...$this->flow->frame($plan, PlanFlow::DECISION, editable: false),
            'title' => $this->translator->trans('billing.plan.released_title'),
            'explanation' => $this->translator->trans('billing.plan.released_explanation'),
            'plan' => $plan,
            'documents' => $documents,
            'document' => self::documentOf($documents, $request->query->getString('einheit')),
            'trail' => $this->page->trail('billing.plan.heading'),
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

        return $this->generateUrl('app_billing_plan', [...$parameters, 'page' => '__PAGE__']);
    }

    private static function filterFrom(Request $request): PlanFilter
    {
        return PlanFilter::of(
            $request->query->getString('objekt'),
            $request->query->getString('jahr'),
            $request->query->getString('zustand'),
            $request->query->getString('q'),
        );
    }

    /**
     * @param list<PlanDocument> $documents
     */
    private static function documentOf(array $documents, string $chosen): ?PlanDocument
    {
        foreach ($documents as $document) {
            if ($document->id() === $chosen) {
                return $document;
            }
        }

        return $documents[0] ?? null;
    }
}
