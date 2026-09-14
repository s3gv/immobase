<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SurveyCosts;
use App\Module\Finance\Application\SurveyLoans;
use App\Module\Finance\Domain\CostBreakdown;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\ReserveBalance;
use App\Module\Finance\Domain\ReserveMovementRepository;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Http\FormInput;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Startseite der Finanzen: wo die Kosten liegen.
 *
 * Nicht eine flache Liste aller Posten, ein- und ausgehend gemischt — die
 * beantwortet keine Frage. Hier stehen die, die ein Verwalter wirklich hat:
 * was kostet das Jahr, wofuer geht es drauf, welches Haus ist teuer, und was
 * davon traegt am Ende der Mieter.
 *
 * Darunter weiter die Zeile je Objekt: die Uebersicht beantwortet, wo man
 * hinsehen sollte, und die Liste bringt einen dorthin.
 */
#[IsGranted(FinancePermissions::VIEW)]
final class FinanceController extends AbstractController
{
    public function __construct(
        private readonly PropertyDirectory $properties,
        private readonly CostItemRepository $items,
        private readonly ReserveMovementRepository $movements,
        private readonly FinancePage $page,
        private readonly SurveyCosts $survey,
        private readonly SurveyLoans $loans,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/finanzen', name: 'app_finance', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $properties = $this->properties->all();
        $ids = array_map(static fn (PropertyBrief $property): string => $property->id, $properties);
        $balances = $this->movements->forProperties($ids);
        $overview = $this->survey->overview(
            (int) (new DateTimeImmutable('today'))->format('Y'),
            FormInput::queryIntOrNull($request, 'jahr'),
        );
        $rest = $this->translator->trans('chart.rest');
        $loans = $this->loans->overview($overview['breakdown']->fiscalYear);

        return $this->render('finance/index.html.twig', [
            ...$overview,
            'properties' => $properties,
            'counts' => $this->items->countsFor($ids),
            'balances' => array_map(
                static fn (PropertyBrief $property): ReserveBalance => $balances[$property->id] ?? ReserveBalance::of([]),
                array_combine($ids, $properties),
            ),
            'reserves' => self::sumOf($balances),
            'loans' => $loans,
            'byKind' => CostBreakdown::largest($overview['breakdown']->byKind, 6, $rest),
            'byProperty' => CostBreakdown::largest($overview['breakdown']->byProperty, 6, $rest),
            'trail' => $this->page->trail(),
        ]);
    }

    /**
     * Was insgesamt zurueckgelegt ist.
     *
     * @param array<string, ReserveBalance> $balances
     */
    private static function sumOf(array $balances): Money
    {
        $sum = Money::zero();

        foreach ($balances as $balance) {
            $sum = $sum->plus($balance->total());
        }

        return $sum;
    }
}
