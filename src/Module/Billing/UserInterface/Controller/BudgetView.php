<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeBudget;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetNeed;
use App\Module\Billing\Domain\LevyPurpose;
use App\Module\Billing\Domain\MeasureKind;
use App\Module\Finance\Contract\CostCatalogue;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Contract\ReserveDirectory;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was jeder Budgetschritt anzuzeigen hat.
 *
 * Getrennt vom Controller, weil das Zusammentragen mehr Zeilen braucht als das
 * Entscheiden — und weil die Vorschau dieselbe Berechnung benutzt wie die
 * Freigabe.
 */
final readonly class BudgetView
{
    /** So weit voraus und zurueck laesst sich ein erstes Jahr waehlen. */
    private const int YEARS_AHEAD = 3;
    private const int YEARS_BACK = 1;

    public function __construct(
        private BudgetFlowPage $page,
        private ComposeBudget $compose,
        private CostCatalogue $catalogue,
        private ReserveDirectory $reserve,
        private PropertyDirectory $properties,
        private BillingPage $trail,
    ) {
    }

    /**
     * Der erste Schritt, bevor es den Plan gibt.
     *
     * @return array<string, mixed>
     */
    public function start(Request $request): array
    {
        return [
            ...$this->page->frame(null, BudgetFlow::MEASURE),
            'budget' => null,
            'properties' => $this->weg(),
            'years' => self::years(),
            'defaultYear' => self::thisYear(),
            'kinds' => MeasureKind::cases(),
            'submitted' => $request->request->all(),
            'trail' => $this->trail->trail('billing.budget.heading'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function of(Budget $budget, string $step): array
    {
        $proposal = $this->compose->of($budget);
        $units = $this->compose->unitsOf($budget);

        return [
            ...$this->page->frame($budget, $step),
            'budget' => $budget,
            'properties' => $this->weg(),
            'years' => self::years(),
            'defaultYear' => self::thisYear(),
            'kinds' => MeasureKind::cases(),
            'submitted' => [],
            'positions' => $budget->positions(),
            'need' => BudgetNeed::of($budget),
            'needPerYear' => BudgetNeed::perYear($budget),
            'proposal' => $proposal,
            'keys' => $this->catalogue->keysFor($budget->propertyId()),
            'intervals' => self::intervals(),
            'levyPurposes' => LevyPurpose::cases(),
            'reserveBalance' => $this->reserve->balanceOf($budget->propertyId()),
            'units' => $units,
            'approved' => $this->compose->approvalsOf($budget),
            'trail' => $this->trail->trail('billing.budget.heading'),
        ];
    }

    /**
     * Die Objekte, fuer die ein Budgetplan ueberhaupt Sinn ergibt.
     *
     * @return list<PropertyBrief>
     */
    private function weg(): array
    {
        return array_values(array_filter(
            $this->properties->all(),
            static fn (PropertyBrief $property): bool => $property->managesWeg,
        ));
    }

    /**
     * Die Jahre zur Wahl, das spaeteste zuerst.
     *
     * Eine Massnahme wird geplant, bevor sie beginnt — nach vorn reicht die
     * Liste darum weiter als zurueck. Ganz ohne Vergangenheit geht es aber
     * nicht: ein Beschluss wird auch einmal nachgetragen.
     *
     * @return list<int>
     */
    private static function years(): array
    {
        $year = self::thisYear();

        return range($year + self::YEARS_AHEAD, $year - self::YEARS_BACK);
    }

    private static function thisYear(): int
    {
        return (int) (new DateTimeImmutable('today'))->format('Y');
    }

    /**
     * Die Raten-Intervalle — ohne „einmalig".
     *
     * Eine einzelne Rate braucht kein Intervall; erst ab der zweiten gibt es
     * einen Abstand.
     *
     * @return list<Interval>
     */
    private static function intervals(): array
    {
        return [Interval::Monthly, Interval::Quarterly, Interval::Annually];
    }
}
