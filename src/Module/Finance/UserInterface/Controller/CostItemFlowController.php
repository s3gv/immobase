<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Contract\DecidedMeasures;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\EntryMode;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\UnitOfMeasure;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Time\Months;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf: eine Kostenposition anlegen und bearbeiten.
 *
 * Jeder Schritt speichert sofort. Es gibt deshalb keinen Zwischenstand in der
 * Sitzung, kein „Abbrechen verwirft" und keinen Unterschied zwischen Anlegen
 * und Bearbeiten: nach dem ersten Schritt ist beides dasselbe.
 */
#[IsGranted(FinancePermissions::EDIT)]
final class CostItemFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireCostItem $item,
        private readonly CostItemStepInput $input,
        private readonly CostItemFlowPage $page,
        private readonly CostKindRepository $kinds,
        private readonly DistributionKeyRepository $keys,
        private readonly DecidedMeasures $measures,
        private readonly PropertyDirectory $properties,
        private readonly CostItemView $view,
    ) {
    }

    #[Route('/finanzen/kosten/neu', name: 'app_finance_item_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->show(null, CostItemFlow::ASSIGNMENT, $request);
        }

        $this->guard($request);
        $created = $this->input->create($request);

        if (null === $created['item']) {
            return $this->show(null, CostItemFlow::ASSIGNMENT, $request, $created['errors']);
        }

        $this->addFlash('success', 'finance.item.created');

        return $this->toStep($created['item'], CostItemFlow::DUE);
    }

    #[Route(
        '/finanzen/kosten/{number}/bearbeiten/{step}',
        name: 'app_finance_item_edit',
        requirements: ['number' => '\d+', 'step' => '[a-z]+'],
        defaults: ['step' => CostItemFlow::ASSIGNMENT],
        methods: ['GET', 'POST'],
    )]
    public function edit(int $number, string $step, Request $request): Response
    {
        $item = ($this->item)($number);
        $current = CostItemFlow::known($step);

        if (!$request->isMethod('POST')) {
            return $this->show($item, $current, $request);
        }

        $this->guard($request);
        $errors = $this->input->apply($current, $request, $item);

        if ([] !== $errors) {
            return $this->show($item, $current, $request, $errors);
        }

        return $this->onwards($item, $current, $request);
    }

    /** Weiter, zurueck — oder fertig. */
    private function onwards(CostItem $item, string $step, Request $request): Response
    {
        if ('back' === $request->request->getString('direction')) {
            return $this->toStep($item, CostItemFlow::previous($step) ?? CostItemFlow::ASSIGNMENT);
        }

        $next = CostItemFlow::next($step);

        if (null !== $next) {
            return $this->toStep($item, $next);
        }

        $this->addFlash('success', 'finance.item.saved');

        return $this->redirectToRoute('app_finance_item_show', ['number' => $item->number()]);
    }

    private function toStep(CostItem $item, string $step): Response
    {
        return $this->redirectToRoute('app_finance_item_edit', ['number' => $item->number(), 'step' => $step]);
    }

    /**
     * Nach einem Fehler steht das Abgeschickte im Formular und nicht der
     * gespeicherte Stand: wer sich vertippt hat, will die Stelle verbessern.
     *
     * @param array<string, string> $errors
     */
    private function show(?CostItem $item, string $step, Request $request, array $errors = []): Response
    {
        return $this->render('finance/steps/'.$step.'.html.twig', [
            ...$this->page->parameters($item, $step, $errors),
            'submitted' => [] === $errors ? [] : $request->request->all(),
            'properties' => $this->properties->all(),
            'kinds' => $this->kinds->all(),
            // Beim Anlegen steht das Objekt erst im selben Schritt fest —
            // also alle Schlüssel zur Wahl, mit ihrem Objekt dahinter. Beim
            // Bearbeiten reichen die, die zu diesem Objekt gehören.
            'keys' => null === $item ? $this->keys->all() : $this->keys->forProperty($item->propertyId()),
            'keyProperties' => $this->keyProperties(),
            'intervals' => Interval::cases(),
            'months' => Months::forChoice(),
            'modes' => EntryMode::cases(),
            'measures' => UnitOfMeasure::cases(),
            // Die beschlossenen Massnahmen des Objekts — erst wenn es
            // feststeht, also nicht im ersten Schritt.
            'decidedMeasures' => null === $item ? [] : $this->measures->forProperty($item->propertyId()),
            // Die Einheiten und ihre erfassten Mengen braucht nur der Schritt,
            // in dem sie stehen — sonst kostet jede Seite eine Abfrage mehr.
            ...(null !== $item && CostItemFlow::AMOUNTS === $step
                ? $this->view->measuring($item)
                : ['units' => [], 'recorded' => []]),
        ]);
    }

    /**
     * Zu welchem Objekt ein Schluessel gehoert — fuer die Beschriftung.
     *
     * @return array<string, string>
     */
    private function keyProperties(): array
    {
        $names = [];

        foreach ($this->properties->all() as $property) {
            $names[$property->id] = $property->oneLine();
        }

        return $names;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('finance_item_flow', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
