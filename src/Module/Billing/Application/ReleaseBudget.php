<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetIsDecided;
use App\Module\Billing\Domain\BudgetIsIncomplete;
use App\Module\Billing\Domain\BudgetReference;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\ProposedBudget;
use App\Module\Billing\Domain\ProposedShare;
use App\Module\Finance\Contract\DecidedLoan;
use App\Module\Finance\Contract\DecidedLoans;
use App\Module\Finance\Contract\PlannedLevy;
use App\Module\Finance\Contract\SpecialLevies;
use App\Module\Finance\Contract\WithdrawnLevy;
use DateTimeImmutable;

/**
 * Ein Budgetplan wird beschlossen — und die Sonderumlage wird faellig.
 *
 * Sieht der Beschluss ein Darlehen vor, entsteht dabei auch dieses — einmal
 * je Massnahme. Was danach daran passiert, passiert in den Finanzen: die Bank
 * kommt dazu, der wirkliche Tag der ersten Rate, vielleicht eine
 * Sondertilgung. Eine Berichtigung des Beschlusses fasst es nicht mehr an.
 *
 * Zwei Dinge passieren hier, und sie gehoeren zusammen: die Schreiben werden
 * eingefroren, und je Einheit und Rate entsteht eine faellige Zahlung. Waere
 * das zweierlei, stuende die Sonderumlage nur auf einem Blatt — die
 * Zahlungsseite wuesste nichts von ihr, und der Vermoegensbericht auch nicht.
 *
 * **Und zwar in einer Transaktion.** Scheitert die siebte von zwoelf
 * Zahlungen, stuende sonst ein beschlossener Plan mit halb geschriebener
 * Umlage da — und ein zweiter Versuch waere gesperrt, weil der Plan ja schon
 * beschlossen ist.
 *
 * **Der Beschluss rechnet neu, und zwar absichtlich.** Bei einer baulichen
 * Veraenderung folgt der Verteilerkreis aus dem Abstimmungsergebnis (§ 21
 * WEG); das kennt erst der Beschluss. Die eingefrorene Vorlage zeigt, was
 * vorlag — beschlossen wird, was die Versammlung entschieden hat.
 */
final readonly class ReleaseBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private ComposeBudget $compose,
        private SpecialLevies $levies,
        private DecidedLoans $loans,
    ) {
    }

    /**
     * @throws BudgetIsDecided
     * @throws BudgetIsIncomplete
     */
    public function release(Budget $budget, DateTimeImmutable $on): void
    {
        if (!$budget->stage()->isOpen()) {
            throw BudgetIsDecided::already();
        }

        if ($budget->measure()->kind()->asksAboutTheVote() && !$budget->verdict()->wasCounted()) {
            throw BudgetIsIncomplete::theVoteWasNotCounted();
        }

        $proposal = $this->compose->of($budget);

        if ([] !== $proposal->missing) {
            throw BudgetIsIncomplete::theFundingDoesNotCover();
        }

        if ([] === $proposal->shares) {
            throw BudgetIsIncomplete::thereIsNoOneToSendTo();
        }

        FreezeBudget::of($budget, $proposal);
        $budget->releaseOn($on, $proposal->bearing);

        $this->budgets->atomically(function () use ($budget, $proposal, $on): void {
            $this->budgets->save($budget);
            $this->levies->decided(self::leviesOf($budget, $proposal), $this->replaced($budget));
            $this->borrow($budget, $on);
        });
    }

    /**
     * Aus dem beschlossenen Finanzierungsweg wird ein gefuehrtes Darlehen.
     *
     * Der Beschluss nennt Summe, Zins und Rate oder Laufzeit — mehr weiss er
     * nicht. Die Bank und der wirkliche Tag der ersten Rate kommen spaeter,
     * und zwar in den Finanzen: dort ist das Darlehen zu Hause, hier stand
     * nur der Plan, es aufzunehmen.
     *
     * Der Tag der Freigabe haelt die erste Rate. Er ist die ehrlichste
     * Vermutung — vor dem Beschluss wird kein Vertrag unterschrieben.
     */
    private function borrow(Budget $budget, DateTimeImmutable $on): void
    {
        $funding = $budget->funding();

        if ($funding->loan()->isZero()) {
            return;
        }

        $this->loans->decided(new DecidedLoan(
            $budget->propertyId(),
            BudgetReference::forTheMeasure($budget),
            $budget->measure()->label(),
            $funding->loan(),
            $funding->loanRateBps(),
            $on,
            $funding->loanPayment(),
            $funding->loanMonths(),
        ));
    }

    /**
     * Was die Fassung davor faellig gestellt hat.
     *
     * Eine Berichtigung ersetzt den Beschluss und nicht nur das Blatt: sonst
     * schuldete die Einheit nach „drei Vierteljahresraten werden acht
     * Monatsraten" beides. Gefragt wird die Vorgaengerfassung selbst — ihre
     * Faelligkeiten stehen in ihrer Finanzierung, ihre Zahler in ihren
     * eingefrorenen Schreiben.
     *
     * Zurueckgenommen wird dabei nur, was zu **dieser** Massnahme gehoert:
     * die Referenz ist ueber alle Fassungen hinweg dieselbe, und eine andere
     * Massnahme, die zufaellig am selben Tag faellig ist, geht es nichts an.
     *
     * @return list<WithdrawnLevy>
     */
    private function replaced(Budget $budget): array
    {
        $before = $budget->edition()->correctsId();
        $previous = null === $before ? null : $this->budgets->byId($before);

        if (null === $previous) {
            return [];
        }

        $reference = BudgetReference::forTheMeasure($previous);
        $withdrawn = [];

        foreach (FundingSchedules::levyDates($previous->funding()) as $due) {
            foreach ($previous->documents() as $document) {
                if (!$document->levy()->isZero()) {
                    $withdrawn[] = new WithdrawnLevy($document->unitId(), $due, $reference);
                }
            }
        }

        return $withdrawn;
    }

    /**
     * Je Einheit und Rate eine faellige Zahlung.
     *
     * @return list<PlannedLevy>
     */
    private static function leviesOf(Budget $budget, ProposedBudget $proposal): array
    {
        $levies = [];

        foreach ($proposal->shares as $share) {
            $levies = [...$levies, ...self::partsOf($budget, $proposal, $share)];
        }

        return $levies;
    }

    /**
     * @return list<PlannedLevy>
     */
    private static function partsOf(Budget $budget, ProposedBudget $proposal, ProposedShare $share): array
    {
        $reference = BudgetReference::forTheMeasure($budget);
        $parts = [];

        foreach ($proposal->levyDates as $at => $due) {
            $amount = $share->levyParts[$at] ?? null;

            if (null !== $amount && !$amount->isZero()) {
                $parts[] = new PlannedLevy(
                    $share->unitId,
                    $due,
                    $amount,
                    $reference,
                    $budget->funding()->levyPurpose()->feedsTheReserve(),
                );
            }
        }

        return $parts;
    }
}
