<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetNeed;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\CostBearing;
use App\Module\Billing\Domain\ProposedBudget;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Billing\Domain\ProposedShare;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Property\Contract\UnitHouseholds;
use App\Module\Tenancy\Contract\TenancySpans;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Berechnung eines Budgetplans.
 *
 * Einmal geschrieben, zweimal benutzt: die Vorschau rechnet damit, und die
 * Freigabe friert deren Ergebnis ein. Was der Mensch geprueft hat, ist was
 * gespeichert wird.
 *
 * **Ein Schreiben bekommt, wer traegt.** Wer nach § 21 Abs. 3 WEG nicht zahlt,
 * bekommt keine Zahlungsaufforderung — der Beschluss selbst geht die
 * Versammlung ohnehin als Ganzes an. Wer traegt, entscheidet
 * {@see WhoBearsTheCosts}.
 */
final readonly class ComposeBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private UnitDirectory $units,
        private TenancySpans $spans,
        private UnitHouseholds $households,
        private ShareOutCosts $shareOut,
        private OwnersOnADay $recipients,
        private StatementPeriod $period,
    ) {
    }

    public function of(Budget $budget): ProposedBudget
    {
        $units = $this->unitsOf($budget);
        $approvals = $this->approvalsOf($budget);
        $bearing = WhoBearsTheCosts::of($budget, $units, $approvals);
        $bearers = WhoBearsTheCosts::amongst($units, $approvals, $bearing);

        $need = BudgetNeed::of($budget);
        $loan = FundingSchedules::repayment($budget->funding());
        $saving = FundingSchedules::savingPerYear($budget->funding());
        $shared = $this->shared($budget, $bearers, $need, $loan->payment ?? Money::zero());
        $whom = $this->recipients->on($bearers, $this->firstDayOf($budget));

        return new ProposedBudget(
            $need,
            $budget->funding()->total(),
            $bearing,
            $this->sharesOf($budget, $bearers, $shared['amounts'], $whom),
            $loan,
            $saving,
            FundingSchedules::levyDates($budget->funding()),
            [...$shared['missing'], ...BudgetGaps::of($budget, $need->minus($budget->funding()->total()), $bearers, $whom)],
        );
    }

    /** Wer traegt — fuer die Schritte, die die Einheiten zeigen. */
    public function bearingOf(Budget $budget): CostBearing
    {
        return WhoBearsTheCosts::of($budget, $this->unitsOf($budget), $this->approvalsOf($budget));
    }

    /**
     * Welche Einheiten zugestimmt haben.
     *
     * @return list<string>
     */
    public function approvalsOf(Budget $budget): array
    {
        return $this->budgets->approvalsOf($budget->id());
    }

    /**
     * @return list<UnitBrief> nach Nummer sortiert
     */
    public function unitsOf(Budget $budget): array
    {
        $units = $this->units->ofProperty($budget->propertyNumber());
        usort($units, static fn (UnitBrief $one, UnitBrief $other): int => $one->number <=> $other->number);

        return $units;
    }

    /**
     * @param list<UnitBrief>                                      $bearers
     * @param array<string, array<string, ProposedLine>>           $amounts
     * @param array<string, array{label: string, address: string}> $whom
     *
     * @return list<ProposedShare>
     */
    private function sharesOf(Budget $budget, array $bearers, array $amounts, array $whom): array
    {
        $parts = $budget->funding()->levyParts();
        $shares = [];

        foreach ($bearers as $unit) {
            $addressed = $whom[$unit->id] ?? null;
            $lines = $amounts[$unit->id] ?? [];

            if (null === $addressed) {
                continue;
            }

            $levy = self::amountOf($lines, BudgetShares::LEVY);
            $shares[] = new ProposedShare(
                $unit->id,
                $unit->number,
                $unit->label,
                $addressed['label'],
                $addressed['address'],
                self::amountOf($lines, BudgetShares::NEED),
                $levy,
                self::amountOf($lines, BudgetShares::SAVING),
                self::amountOf($lines, BudgetShares::LOAN),
                $levy->isZero() ? [] : FundingSchedules::instalments($levy, $parts),
            );
        }

        return $shares;
    }

    /**
     * Die Verteilung der vier Betraege.
     *
     * Mietzeiten und Personenzahlen kommen mit, weil es Schluessel gibt, die
     * daran haengen. Tagesanteilig gerechnet wird trotzdem nie.
     *
     * @param list<UnitBrief> $bearers
     *
     * @return array{amounts: array<string, array<string, ProposedLine>>, missing: list<\App\Module\Billing\Domain\MissingFigure>}
     */
    private function shared(Budget $budget, array $bearers, Money $need, Money $rate): array
    {
        $ids = array_map(static fn (UnitBrief $unit): string => $unit->id, $bearers);
        $from = $this->firstDayOf($budget);
        $to = $this->period->of($budget->propertyId(), $budget->measure()->firstYear())->to();
        $saving = FundingSchedules::savingPerYear($budget->funding());

        return $this->shareOut->of(
            BudgetShares::records($budget, [
                BudgetShares::NEED => $need,
                BudgetShares::LEVY => $budget->funding()->levy(),
                BudgetShares::SAVING => self::firstOf($saving),
                BudgetShares::LOAN => $rate,
            ]),
            $bearers,
            $this->spans->inPeriod($ids, $from, $to),
            $this->households->inPeriod($ids, $from, $to),
            $from,
            $to,
        );
    }

    /**
     * Die Zufuehrung des ersten Sparjahres — sie steht auf dem Blatt.
     *
     * Die Jahre unterscheiden sich hoechstens um einen Cent; welches genommen
     * wird, aendert an der Auskunft nichts, und „je Jahr" ist die Auskunft.
     *
     * @param array<int, Money> $saving
     */
    private static function firstOf(array $saving): Money
    {
        foreach ($saving as $amount) {
            return $amount;
        }

        return Money::zero();
    }

    private function firstDayOf(Budget $budget): DateTimeImmutable
    {
        return $this->period->of($budget->propertyId(), $budget->measure()->firstYear())->from();
    }

    /**
     * @param array<string, ProposedLine> $lines
     */
    private static function amountOf(array $lines, string $id): Money
    {
        return $lines[$id]->amount ?? Money::zero();
    }
}
