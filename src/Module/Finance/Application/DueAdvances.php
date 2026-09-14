<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\Due;
use App\Module\Finance\Domain\DueDates;
use App\Module\Finance\Domain\HouseMoney;
use App\Module\Finance\Domain\HouseMoneyRepository;
use App\Module\Tenancy\Contract\AdvanceStep;
use App\Module\Tenancy\Contract\TenancySpan;
use App\Module\Tenancy\Contract\TenancySpans;
use App\Shared\Money\Money;
use App\Shared\Time\Windows;
use DateTimeImmutable;

/**
 * Was eine Einheit in einem Wirtschaftsjahr schuldete.
 *
 * Zwei Quellen, ein Ergebnis: das Hausgeld steht als Staffel in den Finanzen,
 * die Nebenkosten stehen im Mietvertrag und werden ueber den Vertrag gelesen.
 * Beide muenden in dieselbe Frage — an welchem Tag war wie viel faellig.
 *
 * Der Rhythmus kommt aus dem Wirtschaftsjahr und nicht aus der Staffelstufe;
 * siehe {@see DueDates}. Die Stufe sagt nur, wie viel an dem Tag galt.
 */
final readonly class DueAdvances
{
    public function __construct(
        private HouseMoneyRepository $steps,
        private TenancySpans $spans,
    ) {
    }

    /**
     * @return array<string, Due> Schluessel auf die Faelligkeit
     */
    public function forUnit(
        string $unitId,
        DateTimeImmutable $yearBegins,
        DateTimeImmutable $yearEnds,
    ): array {
        return [
            ...$this->houseMoney($unitId, $yearBegins, $yearEnds),
            ...$this->operatingCosts($unitId, $yearBegins, $yearEnds),
        ];
    }

    /**
     * @return array<string, Due>
     */
    private function houseMoney(
        string $unitId,
        DateTimeImmutable $yearBegins,
        DateTimeImmutable $yearEnds,
    ): array {
        $due = [];
        $schedule = $this->steps->forUnits([$unitId])[$unitId] ?? null;
        $windows = Windows::within(
            null === $schedule ? [] : $schedule->steps(),
            static fn (HouseMoney $step): DateTimeImmutable => $step->startsOn(),
            $yearBegins,
            $yearEnds,
        );

        foreach ($windows as $window) {
            foreach (self::monthsIn($window['from'], $window['to'], $yearBegins) as $index => $day) {
                if (DueDates::isDue($index, $window['step']->interval())) {
                    $due += self::at(AdvanceKind::HouseMoney, $day, $window['step']->amount());
                }
            }
        }

        return $due;
    }

    /**
     * Die Nebenkosten sind monatlich — eine Miete ist es.
     *
     * @return array<string, Due>
     */
    private function operatingCosts(
        string $unitId,
        DateTimeImmutable $yearBegins,
        DateTimeImmutable $yearEnds,
    ): array {
        $due = [];
        $spans = $this->spans->inPeriod([$unitId], $yearBegins, $yearEnds)[$unitId] ?? [];

        foreach ($spans as $span) {
            foreach (self::stepsOf($span) as $step) {
                foreach (self::monthsIn($step->from, $step->to, $yearBegins) as $day) {
                    $due += self::at(
                        AdvanceKind::OperatingCosts,
                        $day,
                        self::owed($span, $step->operatingCosts->plus($step->heating)),
                    );
                }
            }
        }

        return $due;
    }

    /**
     * Die Monatsersten des Jahres, die in diesen Zeitraum fallen.
     *
     * Der Schluessel ist die Nummer des Monats im Wirtschaftsjahr — daran
     * haengt der Rhythmus, und der zaehlt ab dem Jahresbeginn.
     *
     * @return array<int, DateTimeImmutable>
     */
    private static function monthsIn(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $yearBegins,
    ): array {
        $months = [];

        foreach (DueDates::monthsOf($yearBegins) as $index => $day) {
            if ($day >= $from && $day <= $to) {
                $months[$index] = $day;
            }
        }

        return $months;
    }

    /**
     * Was tatsaechlich ueberwiesen wird.
     *
     * Mit Umsatzsteuer vermietet, weist die Dauermietrechnung auch auf die
     * Vorauszahlung Steuer aus — der Mieter zahlt brutto. Erwartet wird, was
     * ankommen soll, sonst stuende jeder Monat als Ueberzahlung da und die
     * Abrechnung zoege zu wenig ab.
     */
    private static function owed(TenancySpan $span, Money $net): Money
    {
        return $span->vatCharged ? $net->plus($net->basisPoints($span->vatRateBps)) : $net;
    }

    /** @return list<AdvanceStep> */
    private static function stepsOf(TenancySpan $span): array
    {
        return $span->advances;
    }

    /**
     * @return array<string, Due>
     */
    private static function at(AdvanceKind $kind, DateTimeImmutable $day, Money $expected): array
    {
        $due = new Due($kind, $day, $expected);

        return [$due->key() => $due];
    }
}
