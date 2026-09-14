<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;
use App\Shared\Money\RepaymentPlan;
use DateTimeImmutable;

/**
 * Ein Budgetplan, durchgerechnet.
 *
 * Einmal gerechnet, zweimal benutzt: die Vorschau zeigt ihn, und die Freigabe
 * friert ihn ein. Was der Mensch geprueft hat, ist was gespeichert wird.
 *
 * Die **Luecke** ist das Feld, auf das es ankommt. Ist sie nicht null, deckt
 * die Finanzierung den Bedarf nicht — und beschlossen wuerde eine Massnahme,
 * fuer die das Geld nicht reicht.
 */
final readonly class ProposedBudget
{
    /**
     * @param list<ProposedShare>     $shares
     * @param array<int, Money>       $savingPerYear Jahr auf Betrag
     * @param list<DateTimeImmutable> $levyDates
     * @param list<MissingFigure>     $missing
     */
    public function __construct(
        public Money $need,
        public Money $funded,
        public CostBearing $bearing,
        public array $shares,
        public ?RepaymentPlan $loan,
        public array $savingPerYear,
        public array $levyDates,
        public array $missing,
    ) {
    }

    /** Was zur Deckung fehlt — negativ heisst: es ist zu viel eingeplant. */
    public function gap(): Money
    {
        return $this->need->minus($this->funded);
    }

    public function isCovered(): bool
    {
        return $this->gap()->isZero();
    }

    /** Die Zufuehrung eines Jahres — null, wenn in diesem Jahr nicht angespart wird. */
    public function savingIn(int $year): ?Money
    {
        return $this->savingPerYear[$year] ?? null;
    }

    /**
     * Der Ratenplan der Sonderumlage: je Faelligkeit ein Tag und eine Summe.
     *
     * Die Summe ist die der Gemeinschaft — was an diesem Tag insgesamt
     * eingehen muss. Was die einzelne Einheit zahlt, steht in ihrer Zeile;
     * beides nebeneinander in einer Tabelle ergaebe eine Matrix, die bei
     * zwoelf Raten seitwaerts aus dem Blatt laeuft.
     *
     * @return list<array{day: DateTimeImmutable, amount: Money}>
     */
    public function levySchedule(): array
    {
        $plan = [];

        foreach ($this->levyDates as $at => $day) {
            $sum = Money::zero();

            foreach ($this->shares as $share) {
                $sum = $sum->plus($share->levyParts[$at] ?? Money::zero());
            }

            $plan[] = ['day' => $day, 'amount' => $sum];
        }

        return $plan;
    }

    public function shareFor(string $unitId): ?ProposedShare
    {
        foreach ($this->shares as $share) {
            if ($share->unitId === $unitId) {
                return $share;
            }
        }

        return null;
    }
}
