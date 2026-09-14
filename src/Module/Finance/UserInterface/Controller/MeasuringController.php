<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SaveCostItem;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\KeyBelongsToAnotherProperty;
use App\Module\Finance\Domain\QuantitiesAreRecorded;
use App\Module\Finance\Domain\UnitOfMeasure;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Womit eine Kostenposition gemessen wird.
 *
 * Verteilerschluessel und Maszeinheit stehen im Schritt „Betraege", weil sie
 * dort gebraucht werden: der Schluessel entscheidet, ob es je Einheit eine
 * Zeile gibt, und die Maszeinheit steht hinter jeder Menge darin. Wer beim
 * Erfassen merkt, dass der falsche Schluessel dranhaengt, soll ihn dort
 * geradeziehen koennen und nicht zwei Schritte zurueckspringen muessen.
 *
 * Es ist derselbe Schluessel wie im Schritt „Zuordnung" — ein Feld, zwei
 * Orte, an denen es zu sehen ist. Keine zweite Wahrheit.
 */
#[IsGranted(FinancePermissions::EDIT)]
final class MeasuringController extends AbstractController
{
    public function __construct(
        private readonly RequireCostItem $item,
        private readonly DistributionKeyRepository $keys,
        private readonly SaveCostItem $save,
    ) {
    }

    #[Route(
        '/finanzen/kosten/{number}/erfassung',
        name: 'app_finance_item_measuring',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function save(int $number, Request $request): Response
    {
        $item = ($this->item)($number);

        if (!$this->isCsrfTokenValid('finance_year_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $this->addFlash(...$this->measured($item, $request));

        return $this->redirectToRoute('app_finance_item_edit', [
            'number' => $number,
            'step' => CostItemFlow::AMOUNTS,
        ]);
    }

    /**
     * Speichern und sagen, wie es ausging.
     *
     * @return array{string, string} Art der Meldung und ihr Schluessel
     */
    private function measured(CostItem $item, Request $request): array
    {
        $key = $this->keys->byId($request->request->getString('keyId'))
            ?? throw new NotFoundHttpException('Diesen Verteilerschlüssel gibt es nicht.');

        try {
            $this->save->measuredBy($item, $key, UnitOfMeasure::tryFrom($request->request->getString('measure')));
        } catch (KeyBelongsToAnotherProperty) {
            return ['error', 'finance.error.key_foreign'];
        } catch (QuantitiesAreRecorded) {
            return ['error', 'finance.error.quantities_recorded'];
        }

        return ['success', 'finance.item.saved'];
    }
}
