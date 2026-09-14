<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SaveLoan;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\LoanFilter;
use App\Module\Finance\Domain\LoanRepository;
use App\Module\Finance\Infrastructure\DoctrineLoanRepository;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Darlehen: Uebersicht, Seite, Loeschen.
 *
 * Anlegen und Bearbeiten laufen ueber den Ablauf nebenan, Sondertilgung und
 * Zinsaenderung ueber den Verlauf — das Nachschlagen ist eine andere Sache
 * als beides.
 */
#[IsGranted(FinancePermissions::VIEW)]
final class LoanController extends AbstractController
{
    private const string DEFAULT_SORT = 'nummer';

    public function __construct(
        private readonly LoanRepository $loans,
        private readonly RequireLoan $loan,
        private readonly LoanPage $page,
        private readonly LoanView $view,
        private readonly SaveLoan $save,
        private readonly FinancePage $finance,
        private readonly PropertyDirectory $properties,
    ) {
    }

    #[Route('/finanzen/darlehen', name: 'app_finance_loan', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = LoanFilter::of($request->query->getString('objekt'), $request->query->getString('q'));
        $sort = self::sortFrom($request);
        $page = Page::of($request->query->getInt('page', 1), $this->loans->countMatching($filter));

        return $this->render('finance/loans.html.twig', [
            'rows' => $this->view->rows($this->loans->matching($filter, $page, $sort)),
            'page' => $page,
            'filter' => $filter,
            'sort' => $sort,
            'sortUrls' => $this->page->sortUrls($request, $sort, array_keys(DoctrineLoanRepository::SORTABLE)),
            'url' => $this->page->listUrl($request),
            'properties' => $this->properties->all(),
            'trail' => $this->finance->trail('finance.loan.heading'),
        ]);
    }

    #[Route(
        '/finanzen/darlehen/{number}',
        name: 'app_finance_loan_show',
        requirements: ['number' => '\d+'],
        methods: ['GET'],
    )]
    public function show(int $number, Request $request): Response
    {
        $loan = ($this->loan)($number);
        $section = LoanPage::known($request->query->getString('abschnitt'));

        return $this->render('finance/loan/'.$section.'.html.twig', [
            ...$this->page->sections($loan, $section),
            ...$this->view->data($loan),
        ]);
    }

    /**
     * Loeschen — und zwar wirklich.
     *
     * Anders als bei einer Ruecklagenbuchung haengt an einem Darlehen keine
     * Abrechnung: was es in einen Wirtschaftsplan gebracht hat, steht dort
     * als eigene Zeile und bleibt stehen. Wer es falsch angelegt hat, soll
     * es loswerden koennen.
     */
    #[IsGranted(FinancePermissions::DELETE)]
    #[Route(
        '/finanzen/darlehen/{number}/loeschen',
        name: 'app_finance_loan_delete',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(int $number, Request $request): Response
    {
        $loan = ($this->loan)($number);

        if (!$this->isCsrfTokenValid('finance_loan_delete_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $this->save->remove($loan);
        $this->addFlash('success', 'finance.loan.deleted');

        return $this->redirectToRoute('app_finance_loan');
    }

    /** Ohne Wunsch die juengsten zuerst: das zuletzt Aufgenommene sucht man am oeftesten. */
    private static function sortFrom(Request $request): Sort
    {
        return Sort::of(
            $request->query->getString('sortieren'),
            $request->query->getString('richtung'),
            array_keys(DoctrineLoanRepository::SORTABLE),
            Sort::by(self::DEFAULT_SORT, Sort::DESCENDING),
        );
    }
}
