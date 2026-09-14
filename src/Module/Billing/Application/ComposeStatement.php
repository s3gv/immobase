<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\FiscalPeriod;
use App\Module\Billing\Domain\MissingFigure;
use App\Module\Billing\Domain\Proposal;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Billing\Domain\ReportedReserve;
use App\Module\Billing\Domain\Statement;
use App\Module\Finance\Contract\AdvanceDirectory;
use App\Module\Finance\Contract\CostDirectory;
use App\Module\Finance\Contract\CostRecord;
use App\Module\Finance\Contract\PaymentRecord;
use App\Module\Finance\Contract\ReserveDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Property\Contract\UnitHouseholds;
use App\Module\Tenancy\Contract\TenancySpan;
use App\Module\Tenancy\Contract\TenancySpans;

/**
 * Die Berechnung.
 *
 * Einmal geschrieben, zweimal benutzt: die Vorschau rechnet damit, und die
 * Freigabe friert genau deren Ergebnis ein. Was der Mensch geprueft hat, ist
 * was gespeichert wird — nicht etwas Aehnliches.
 *
 * Hier steht nur der Ablauf: zusammentragen, auswaehlen, verteilen lassen,
 * zuordnen lassen. Die Verteilung rechnet {@see ShareOutCosts}, die Zuordnung
 * macht {@see ProposeDocuments} — drei Aufgaben, drei Klassen.
 */
final readonly class ComposeStatement
{
    public function __construct(
        private UnitDirectory $units,
        private TenancySpans $spans,
        private UnitHouseholds $households,
        private CostDirectory $costs,
        private AdvanceDirectory $advances,
        private ShareOutCosts $shareOut,
        private ProposeDocuments $assemble,
        private ReserveDirectory $reserve,
    ) {
    }

    /**
     * @param list<string> $chosenCosts    Kennungen der gewaehlten Jahreswerte
     * @param list<string> $chosenPayments Kennungen der gewaehlten Zahlungen
     */
    public function of(Statement $statement, array $chosenCosts, array $chosenPayments): Proposal
    {
        $period = $statement->period();
        $units = $this->unitsOf($statement);
        $ids = array_map(static fn (UnitBrief $unit): string => $unit->id, $units);
        $spans = $this->spans->inPeriod($ids, $period->from(), $period->to());

        $costs = self::chosenCosts(
            $this->costs->forYear($statement->propertyId(), $statement->fiscalYear()),
            $chosenCosts,
        );
        $payments = self::chosenPayments(
            $this->advances->paymentsFor($ids, $statement->fiscalYear()),
            $chosenPayments,
        );

        $shares = $this->shared($costs, $units, $spans, $ids, $period);
        $documents = $this->assemble->of(
            $statement,
            $units,
            $spans,
            $costs,
            $shares['amounts'],
            $payments,
            $period->from(),
            $period->to(),
        );

        return new Proposal($documents, $shares['missing'], $this->reserveIn($statement));
    }

    /**
     * Alles, was zur Wahl steht — fuer die beiden Auswahlschritte.
     *
     * @return array{costs: list<CostRecord>, payments: list<PaymentRecord>}
     */
    public function offered(Statement $statement): array
    {
        $ids = array_map(
            static fn (UnitBrief $unit): string => $unit->id,
            $this->units->ofProperty($statement->propertyNumber()),
        );

        return [
            'costs' => $this->costs->forYear($statement->propertyId(), $statement->fiscalYear()),
            'payments' => $this->advances->paymentsFor($ids, $statement->fiscalYear()),
        ];
    }

    /**
     * Die Entwicklung der Erhaltungsruecklage im abgerechneten Jahr.
     *
     * Dieselbe Rechnung wie im Vermoegensbericht und ueber dieselbe Stelle:
     * zwei Schreiben derselben Verwaltung, die verschiedene Bestaende nennen,
     * sind schlimmer als eines ohne Auszug.
     */
    private function reserveIn(Statement $statement): ReportedReserve
    {
        return ReportedReserve::of($this->reserve->standingAt(
            $statement->propertyId(),
            $statement->period()->from(),
            $statement->period()->to(),
        ));
    }

    /**
     * Die Verteilung — mit der Personenzahl fuer die Tage ohne Mietvertrag.
     *
     * @param list<CostRecord>                 $costs
     * @param list<UnitBrief>                  $units
     * @param array<string, list<TenancySpan>> $spans
     * @param list<string>                     $ids
     *
     * @return array{amounts: array<string, array<string, ProposedLine>>, missing: list<MissingFigure>}
     */
    private function shared(array $costs, array $units, array $spans, array $ids, FiscalPeriod $period): array
    {
        return $this->shareOut->of(
            $costs,
            $units,
            $spans,
            $this->households->inPeriod($ids, $period->from(), $period->to()),
            $period->from(),
            $period->to(),
        );
    }

    /**
     * @return list<UnitBrief> nach Nummer sortiert
     */
    private function unitsOf(Statement $statement): array
    {
        $units = $this->units->ofProperty($statement->propertyNumber());
        usort($units, static fn (UnitBrief $one, UnitBrief $other): int => $one->number <=> $other->number);

        return $units;
    }

    /**
     * @param list<CostRecord> $costs
     * @param list<string>     $chosen
     *
     * @return list<CostRecord>
     */
    private static function chosenCosts(array $costs, array $chosen): array
    {
        return array_values(array_filter(
            $costs,
            static fn (CostRecord $cost): bool => \in_array($cost->costYearId, $chosen, true),
        ));
    }

    /**
     * @param list<PaymentRecord> $payments
     * @param list<string>        $chosen
     *
     * @return list<PaymentRecord>
     */
    private static function chosenPayments(array $payments, array $chosen): array
    {
        return array_values(array_filter(
            $payments,
            static fn (PaymentRecord $payment): bool => \in_array($payment->paymentId, $chosen, true),
        ));
    }
}
