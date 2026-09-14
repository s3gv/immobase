<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SaveCostItem;
use App\Module\Finance\Domain\CostItemFilter;
use App\Module\Finance\Domain\CostItemHasBeenRecorded;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\EntryMode;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Infrastructure\DoctrineCostItemRepository;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Ui\Page;
use App\Shared\Ui\Past;
use App\Shared\Ui\Sort;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Kostenpositionen: Uebersicht, Seite, Loeschen.
 *
 * Anlegen und Bearbeiten laufen ueber den Ablauf nebenan — sie sind eine
 * andere Sache als das Nachschlagen.
 */
#[IsGranted(FinancePermissions::VIEW)]
final class CostItemController extends AbstractController
{
    private const string DEFAULT_SORT = 'nummer';

    public function __construct(
        private readonly CostItemRepository $items,
        private readonly RequireCostItem $item,
        private readonly CostItemPage $page,
        private readonly CostItemView $view,
        private readonly CostKindRepository $kinds,
        private readonly PropertyDirectory $properties,
        private readonly SaveCostItem $save,
        private readonly FinancePage $finance,
    ) {
    }

    #[Route('/finanzen/kosten', name: 'app_finance_item', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filterFrom($request);
        $sort = self::sortFrom($request);
        $page = Page::of($request->query->getInt('page', 1), $this->items->countMatching($filter));

        return $this->render('finance/items.html.twig', [
            'rows' => $this->view->rows($this->items->matching($filter, $page, $sort)),
            'page' => $page,
            'filter' => $filter,
            'sort' => $sort,
            'sortUrls' => $this->page->sortUrls($request, $sort, array_keys(DoctrineCostItemRepository::SORTABLE)),
            'url' => $this->page->listUrl($request),
            'properties' => $this->properties->all(),
            'kinds' => $this->kinds->all(),
            'trail' => $this->finance->trail('finance.item.heading'),
        ]);
    }

    #[Route('/finanzen/kosten/{number}', name: 'app_finance_item_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(int $number, Request $request): Response
    {
        $item = ($this->item)($number);
        $section = CostItemPage::known($request->query->getString('abschnitt'));

        return $this->render('finance/detail/'.$section.'.html.twig', [
            ...$this->page->sections($item, $section),
            ...$this->view->data($item),
            ...$this->view->measuring($item),
            'modes' => EntryMode::cases(),
        ]);
    }

    /**
     * Beenden — und mit demselben Knopf wieder aufnehmen.
     *
     * Was einmal gelaufen ist, wird nicht geloescht, sondern beendet. Es
     * steht danach bereit, aber nicht im Weg: die Uebersicht zeigt den
     * laufenden Stand, der Schalter holt das Vergangene dazu.
     */
    #[IsGranted(FinancePermissions::EDIT)]
    #[Route(
        '/finanzen/kosten/{number}/beenden',
        name: 'app_finance_item_end',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function end(int $number, Request $request): Response
    {
        $item = ($this->item)($number);

        if (!$this->isCsrfTokenValid('finance_item_end_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $ending = !$item->isPast();
        $this->save->runUntil($item, $ending ? new DateTimeImmutable('today') : null);
        $this->addFlash('success', $ending ? 'finance.item.ended' : 'finance.item.reopened');

        return $this->redirectToRoute('app_finance_item_show', ['number' => $number]);
    }

    #[IsGranted(FinancePermissions::DELETE)]
    #[Route('/finanzen/kosten/{number}/loeschen', name: 'app_finance_item_delete', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function delete(int $number, Request $request): Response
    {
        $item = ($this->item)($number);

        if (!$this->isCsrfTokenValid('finance_item_delete_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        try {
            $this->save->remove($item);
            $this->addFlash('success', 'finance.item.deleted');
        } catch (CostItemHasBeenRecorded) {
            $this->addFlash('error', 'finance.error.item_recorded');

            return $this->redirectToRoute('app_finance_item_show', ['number' => $number]);
        }

        return $this->redirectToRoute('app_finance_item');
    }

    private static function filterFrom(Request $request): CostItemFilter
    {
        return CostItemFilter::of(
            $request->query->getString('objekt'),
            $request->query->getString('art'),
            $request->query->getString('umlage'),
            $request->query->getString('q'),
            Past::from($request)->shown,
        );
    }

    private static function sortFrom(Request $request): Sort
    {
        return Sort::of(
            $request->query->getString('sortieren'),
            $request->query->getString('richtung'),
            array_keys(DoctrineCostItemRepository::SORTABLE),
            Sort::by(self::DEFAULT_SORT, Sort::DESCENDING),
        );
    }
}
