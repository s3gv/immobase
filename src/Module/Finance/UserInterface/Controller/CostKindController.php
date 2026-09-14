<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\MaintainCostKinds;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindIsPartOfTheCatalogue;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\UsedByAPlan;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Kostenarten: eine Liste, die man einmal im Jahr aufmacht.
 *
 * Kein Ablauf und keine eigene Seite je Art — zwei Angaben, und beide passen
 * in eine Zeile. Dieselbe Gestalt wie die Mietstaffel: jede Zeile ein
 * eigenes Formular, damit eine Aenderung nicht raten muss, welche Zeile
 * gemeint war.
 */
#[IsGranted(FinancePermissions::VIEW)]
final class CostKindController extends AbstractController
{
    public function __construct(
        private readonly CostKindRepository $kinds,
        private readonly MaintainCostKinds $maintain,
        private readonly FinancePage $page,
    ) {
    }

    #[Route('/finanzen/kostenarten', name: 'app_finance_cost_kind', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('finance/cost_kinds.html.twig', [
            'kinds' => $this->kinds->all(),
            'trail' => $this->page->trail('finance.kind.heading'),
        ]);
    }

    #[IsGranted(FinancePermissions::EDIT)]
    #[Route('/finanzen/kostenarten', name: 'app_finance_cost_kind_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->guard($request);

        try {
            $this->maintain->add($request->request->getString('name'), $request->request->getBoolean('apportionable'));
            $this->addFlash('success', 'finance.kind.added');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'finance.error.kind_name_required');
        }

        return $this->redirectToRoute('app_finance_cost_kind');
    }

    #[IsGranted(FinancePermissions::EDIT)]
    #[Route('/finanzen/kostenarten/{id}', name: 'app_finance_cost_kind_change', methods: ['POST'])]
    public function change(string $id, Request $request): Response
    {
        $kind = $this->required($id);
        $this->guard($request);

        if ('' !== $request->request->getString('remove')) {
            return $this->drop($kind);
        }

        try {
            $this->maintain->rename(
                $kind,
                $request->request->getString('name'),
                $request->request->getBoolean('apportionable'),
            );
            $this->addFlash('success', 'finance.kind.changed');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'finance.error.kind_name_required');
        }

        return $this->redirectToRoute('app_finance_cost_kind');
    }

    private function drop(CostKind $kind): Response
    {
        try {
            $this->maintain->drop($kind);
            $this->addFlash('success', 'finance.kind.removed');
        } catch (CostKindIsPartOfTheCatalogue) {
            $this->addFlash('error', 'finance.error.kind_is_system');
        } catch (UsedByAPlan $held) {
            $this->addFlash('error', $held->getMessage());
        }

        return $this->redirectToRoute('app_finance_cost_kind');
    }

    private function required(string $id): CostKind
    {
        return $this->kinds->byId($id)
            ?? throw new NotFoundHttpException('Diese Kostenart gibt es nicht.');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('finance_cost_kind', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
