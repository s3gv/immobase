<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposePlan;
use App\Module\Billing\Application\SurveyPlans;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanDrift;
use App\Module\Billing\Domain\PlanLineKind;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Finance\Contract\CostCatalogue;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Contract\ReserveDirectory;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was jeder Planschritt anzuzeigen hat.
 *
 * Getrennt vom Controller, weil das Zusammentragen mehr Zeilen braucht als das
 * Entscheiden — und weil die Vorschau dieselbe Berechnung benutzt wie die
 * Freigabe.
 */
final readonly class PlanView
{
    /** So weit voraus und zurueck laesst sich ein Planjahr waehlen. */
    private const int YEARS_AHEAD = 1;
    private const int YEARS_BACK = 3;

    public function __construct(
        private PlanFlowPage $page,
        private ComposePlan $compose,
        private CostCatalogue $catalogue,
        private ReserveDirectory $reserve,
        private PropertyDirectory $properties,
        private BillingPage $trail,
    ) {
    }

    /**
     * Der erste Schritt, bevor es den Plan gibt.
     *
     * Nur WEG-Objekte stehen zur Wahl: ohne Miteigentum gibt es keine
     * Vorschuesse, ueber die eine Versammlung beschliessen koennte.
     *
     * @return array<string, mixed>
     */
    public function start(Request $request): array
    {
        return [
            ...$this->page->frame(null, PlanFlow::BASICS),
            'plan' => null,
            'properties' => $this->weg(),
            'years' => self::years(),
            'defaultYear' => SurveyPlans::yearToPlan(),
            'submitted' => $request->request->all(),
            'positions' => [],
            'reserve' => null,
            'drifted' => false,
            'trail' => $this->trail->trail('billing.plan.heading'),
        ];
    }

    /**
     * @param string $recipient Kennung der angezeigten Einheit; leer heisst: die erste
     *
     * @return array<string, mixed>
     */
    public function of(Plan $plan, string $step, string $recipient = ''): array
    {
        $today = $this->compose->of($plan);
        // Liegt eine Vorlage vor, zeigt der Ablauf **sie** und nicht den
        // heutigen Stand: sonst stuenden unter „herausgegeben am 30. Oktober"
        // Zahlen, die an diesem Tag niemand gesehen hat. Dass es heute anders
        // aussaehe, sagt der Hinweis daneben.
        $proposal = PlanDrift::asIssued($plan) ?? $today;

        return [
            ...$this->page->frame($plan, $step),
            'plan' => $plan,
            'properties' => $this->weg(),
            'years' => self::years(),
            'defaultYear' => SurveyPlans::yearToPlan(),
            'submitted' => [],
            'positions' => self::only($plan, PlanLineKind::Cost),
            'reserve' => self::only($plan, PlanLineKind::Reserve)[0] ?? null,
            'reserveBalance' => $this->reserve->balanceOf($plan->propertyId()),
            'kinds' => $this->catalogue->kinds(),
            'keys' => $this->catalogue->keysFor($plan->propertyId()),
            'intervals' => self::intervals(),
            'proposal' => $proposal,
            'drifted' => PlanDrift::between($plan, $today),
            'shown' => $proposal->documentFor($recipient) ?? ($proposal->documents[0] ?? null),
            'trail' => $this->trail->trail('billing.plan.heading'),
        ];
    }

    /**
     * @return list<PlanPosition>
     */
    private static function only(Plan $plan, PlanLineKind $kind): array
    {
        return array_values(array_filter(
            $plan->positions(),
            static fn (PlanPosition $position): bool => $position->lineKind() === $kind,
        ));
    }

    /**
     * Die Objekte, fuer die ein Wirtschaftsplan ueberhaupt Sinn ergibt.
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
     * Die Planjahre zur Wahl, das spaeteste zuerst.
     *
     * Vorbelegt ist nicht das erste, sondern {@see SurveyPlans::yearToPlan()}
     * — das Jahr, fuer das jetzt geplant wird. Ein Jahr weiter steht trotzdem
     * zur Wahl, denn manche Verwaltung plant frueh; zurueck reicht die Liste,
     * weil Plaene nachgetragen werden.
     *
     * @return list<int>
     */
    private static function years(): array
    {
        $planning = SurveyPlans::yearToPlan();

        return range($planning + self::YEARS_AHEAD, $planning - self::YEARS_BACK);
    }

    /**
     * Die Zahlungsintervalle — ohne „einmalig".
     *
     * @return list<Interval>
     */
    private static function intervals(): array
    {
        return [Interval::Monthly, Interval::Quarterly, Interval::Annually];
    }
}
