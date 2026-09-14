<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeBudget;
use App\Module\Billing\Application\CorrectBudget;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\BudgetFilter;
use App\Module\Billing\Domain\BudgetNeed;
use App\Module\Billing\Domain\BudgetReference;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\ResolutionStatus;
use App\Module\Finance\Contract\CostDirectory;
use App\Module\Finance\Contract\MeasureCost;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Http\FormInput;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Liste der Budgetplaene und der Blick in einen beschlossenen.
 */
#[IsGranted(BillingPermissions::VIEW)]
final class BudgetController extends AbstractController
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly ComposeBudget $compose,
        private readonly CorrectBudget $corrections,
        private readonly BillingPage $page,
        private readonly BudgetFlowPage $flow,
        private readonly PropertyDirectory $properties,
        private readonly TranslatorInterface $translator,
        private readonly CostDirectory $costs,
    ) {
    }

    #[Route('/billing/budgetplaene', name: 'app_billing_budget', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filterFrom($request);
        $total = $this->budgets->countMatching($filter);
        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $total);

        return $this->render('billing/budgets.html.twig', [
            'budgets' => $this->budgets->matching($filter, $page),
            'total' => $total,
            'page' => $page,
            'filter' => $filter,
            'properties' => $this->properties->all(),
            'years' => $this->budgets->years(),
            'statuses' => ResolutionStatus::cases(),
            'url' => $this->listUrl($request),
            'trail' => $this->page->trail('billing.budget.heading'),
        ]);
    }

    /**
     * Einen beschlossenen Budgetplan ansehen.
     *
     * **Nur einen beschlossenen.** Auf dieser Seite stehen die Betraege je
     * Einheit; bei einem Entwurf waeren sie frisch gerechnet und von niemandem
     * beschlossen. Das Leserecht ist das weiteste im Modul — was es sieht,
     * sieht jeder im Haus.
     */
    #[Route(
        '/billing/budgetplaene/{id}',
        name: 'app_billing_budget_show',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): Response
    {
        $budget = $this->budgets->byId($id)
            ?? throw $this->createNotFoundException('Diesen Budgetplan gibt es nicht.');

        if ($budget->stage()->isOpen()) {
            throw $this->createNotFoundException('Ein Entwurf wird im Ablauf bearbeitet und nicht angesehen.');
        }

        return $this->render('billing/budget.html.twig', [
            ...$this->flow->frame($budget, BudgetFlow::DECISION, editable: false),
            'title' => $this->translator->trans('billing.budget.decided_title'),
            'explanation' => $this->translator->trans('billing.budget.decided_explanation'),
            'budget' => $budget,
            'need' => BudgetNeed::of($budget),
            'proposal' => $this->compose->of($budget),
            'correctable' => $this->corrections->canBeCorrected($budget),
            // Was von der Massnahme schon bezahlt ist — die Gegenrichtung zur
            // Sonderumlage. Leer, solange niemand eine Rechnung zugeordnet hat.
            ...self::spentOn($this->costs->forMeasure(BudgetReference::forTheMeasure($budget))),
            'trail' => $this->page->trail('billing.budget.heading'),
        ]);
    }

    /**
     * Die Rechnungen der Massnahme und ihre Summe.
     *
     * @param list<MeasureCost> $costs
     *
     * @return array{spent: list<MeasureCost>, spentTotal: Money}
     */
    private static function spentOn(array $costs): array
    {
        $total = Money::zero();

        foreach ($costs as $cost) {
            $total = $total->plus($cost->amount);
        }

        return ['spent' => $costs, 'spentTotal' => $total];
    }

    /** Die Adresse der Liste mit Platzhalter fuer die Seitenzahl. */
    private function listUrl(Request $request): string
    {
        $parameters = [];

        foreach (['objekt', 'jahr', 'zustand', 'q'] as $name) {
            $value = trim($request->query->getString($name));

            if ('' !== $value) {
                $parameters[$name] = $value;
            }
        }

        return $this->generateUrl('app_billing_budget', [...$parameters, 'page' => '__PAGE__']);
    }

    private static function filterFrom(Request $request): BudgetFilter
    {
        return BudgetFilter::of(
            $request->query->getString('objekt'),
            $request->query->getString('jahr'),
            $request->query->getString('zustand'),
            $request->query->getString('q'),
        );
    }
}
