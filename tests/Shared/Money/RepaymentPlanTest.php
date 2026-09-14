<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Money;

use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use App\Shared\Money\PlanChange;
use App\Shared\Money\Repayment;
use App\Shared\Money\RepaymentPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Tilgungsplan.
 *
 * Die Zusicherung, an der alles haengt: **der Plan geht auf null auf, und die
 * Summe der Tilgungen ist auf den Cent das Darlehen.** Eine Rechnung, bei der
 * am Ende ein Cent Restschuld stehen bleibt, ist keine Tilgung, sondern eine
 * Fliesskommazahl mit Anzug.
 */
final class RepaymentPlanTest extends TestCase
{
    public function testTheRepaymentsAddUpToTheLoan(): void
    {
        $plan = RepaymentPlan::withPayment(Money::fromCents(4000000), 420, Money::fromCents(40910));
        $repaid = Money::zero();

        foreach ($plan->schedule as $month) {
            $repaid = $repaid->plus($month->principal);
        }

        self::assertSame(4000000, $repaid->cents(), 'Getilgt wird genau das Darlehen');
        self::assertSame(0, self::lastOf($plan)->balance->cents(), 'Und am Ende steht nichts mehr offen');
    }

    /**
     * Die letzte Rate faellt aus der Reihe — und genau deshalb geht der Plan auf.
     *
     * Sie ist die Restschuld plus ihr Zins. Eine Rechnung, die stur die volle
     * Rate abbucht, ueberzahlt am Ende.
     */
    public function testTheLastPaymentIsTheRestAndItsInterest(): void
    {
        $plan = RepaymentPlan::withPayment(Money::fromCents(100000), 600, Money::fromCents(20000));
        $last = self::lastOf($plan);

        self::assertSame($last->interest->cents() + $last->principal->cents(), $last->payment->cents());
        self::assertLessThan(20000, $last->payment->cents(), 'Kleiner als die Rate');
    }

    /** Zins auf die Restschuld, kaufmaennisch auf den Cent. */
    public function testTheFirstInterestIsTheRateOnTheWholeLoan(): void
    {
        $plan = RepaymentPlan::withPayment(Money::fromCents(4000000), 420, Money::fromCents(40910));

        // 40.000,00 € × 4,20 % ÷ 12 = 140,00 €
        self::assertSame(14000, $plan->first()->interest->cents());
        self::assertSame(26910, $plan->first()->principal->cents());
    }

    /** Ohne Zins ist die Tilgung die Rate. */
    public function testALoanWithoutInterestJustDividesUp(): void
    {
        $plan = RepaymentPlan::overMonths(Money::fromCents(120000), 0, 12);

        self::assertSame(10000, $plan->payment->cents());
        self::assertSame(12, $plan->months());
        self::assertSame(0, $plan->totalInterest()->cents());
    }

    /**
     * Zu einer Laufzeit wird die Rate gesucht — die kleinste, die reicht.
     *
     * Gesucht wird ueber den Plan und nicht ueber eine Formel: eine
     * Intervallschachtelung ueber ganze Cent, die dort endet, wo der Plan
     * innerhalb der Laufzeit auf null geht.
     */
    public function testTheTermFindsTheSmallestPaymentThatWorks(): void
    {
        $plan = RepaymentPlan::overMonths(Money::fromCents(4000000), 420, 120);

        self::assertSame(120, $plan->months());

        $tooLittle = Money::fromCents($plan->payment->cents() - 1);
        self::assertGreaterThan(
            120,
            RepaymentPlan::withPayment(Money::fromCents(4000000), 420, $tooLittle)->months(),
            'Ein Cent weniger, und es dauert länger',
        );
    }

    /**
     * Egal, wie die Zahlen liegen: der Plan geht auf.
     *
     * @param int<0, max> $months
     */
    #[DataProvider('loans')]
    public function testEveryPlanEndsAtZero(int $principal, int $rateBps, int $months): void
    {
        $plan = RepaymentPlan::overMonths(Money::fromCents($principal), $rateBps, $months);
        $repaid = Money::zero();

        foreach ($plan->schedule as $month) {
            $repaid = $repaid->plus($month->principal);
        }

        self::assertLessThanOrEqual($months, $plan->months(), 'Nicht länger als gewollt');
        self::assertSame(0, self::lastOf($plan)->balance->cents());
        self::assertSame($principal, $repaid->cents());
    }

    /**
     * @return list<array{int, int, int}>
     */
    public static function loans(): array
    {
        return [
            [100000, 0, 6],
            [100000, 125, 12],
            [4000000, 420, 120],
            [4000000, 999, 240],
            [12345678, 315, 360],
            [999, 1500, 2],
            [6000000, 420, 1],
        ];
    }

    /** Die Jahresbelastung ist ein Ausschnitt des Plans. */
    public function testTheChargeOfAYearIsTwelveMonths(): void
    {
        $plan = RepaymentPlan::overMonths(Money::fromCents(4000000), 420, 120);
        $first = $plan->between(1, 12);

        self::assertSame($plan->payment->cents() * 12, $first->cents());
        self::assertSame(0, $plan->between(121, 12)->cents(), 'Nach dem Ende kommt nichts mehr');
    }

    /**
     * Eine Rate, die den Zins nicht deckt, tilgt nichts.
     *
     * Die Restschuld waechst, und der Plan endet nie. Das ist eine Eingabe und
     * bekommt eine Absage, die man versteht.
     */
    public function testARateBelowTheInterestIsRefused(): void
    {
        $this->expectException(LoanDoesNotAmortise::class);
        RepaymentPlan::withPayment(Money::fromCents(4000000), 420, Money::fromCents(10000));
    }

    public function testATermOfNothingIsRefused(): void
    {
        $this->expectException(LoanDoesNotAmortise::class);
        RepaymentPlan::overMonths(Money::fromCents(100000), 420, 0);
    }

    /**
     * Eine Sondertilgung verkuerzt den Plan.
     *
     * Sie geht sofort von der Restschuld ab, und ab da traegt jede Rate mehr
     * Tilgung: der Zins rechnet auf eine kleinere Schuld. Der Plan bleibt
     * dabei auf den Cent genau — die letzte Rate ist der Rest.
     */
    public function testAnExtraPaymentShortensThePlan(): void
    {
        $without = RepaymentPlan::withPayment(Money::fromCents(2000000), 420, Money::fromCents(20440));
        $with = RepaymentPlan::withPayment(Money::fromCents(2000000), 420, Money::fromCents(20440), [
            new PlanChange(12, Money::fromCents(500000)),
        ]);

        self::assertLessThan($without->months(), $with->months(), 'Kürzer als ohne');
        self::assertLessThan($without->totalInterest()->cents(), $with->totalInterest()->cents(), 'Und billiger');
        self::assertSame(0, $with->last()->balance->cents(), 'Und er geht auf null auf');

        $repaid = Money::fromCents(500000);

        foreach ($with->schedule as $month) {
            $repaid = $repaid->plus($month->principal);
        }

        self::assertSame(2000000, $repaid->cents(), 'Getilgt wird genau das Darlehen');
    }

    /** Ein neuer Zins ab einem Monat — die Rate bleibt, die Laufzeit nicht. */
    public function testANewRateChangesTheRestOfThePlan(): void
    {
        $cheaper = RepaymentPlan::withPayment(Money::fromCents(2000000), 420, Money::fromCents(20440), [
            new PlanChange(60, null, 200),
        ]);
        $unchanged = RepaymentPlan::withPayment(Money::fromCents(2000000), 420, Money::fromCents(20440));

        self::assertSame(
            self::interestIn($unchanged, 1),
            self::interestIn($cheaper, 1),
            'Vor der Änderung dasselbe',
        );
        self::assertLessThan(
            self::interestIn($unchanged, 61),
            self::interestIn($cheaper, 61),
            'Danach weniger Zins',
        );
        self::assertLessThan($unchanged->months(), $cheaper->months(), 'Und früher fertig');
    }

    /** Wer alles auf einmal tilgt, ist fertig. */
    public function testAnExtraPaymentThatSettlesTheLoanEndsThePlan(): void
    {
        $plan = RepaymentPlan::withPayment(Money::fromCents(1000000), 420, Money::fromCents(20000), [
            new PlanChange(3, Money::fromCents(10000000)),
        ]);

        self::assertSame(3, $plan->months(), 'Nach der dritten Rate ist Schluss');
        self::assertSame(0, $plan->last()->balance->cents());
    }

    /** Der Zins eines bestimmten Monats. */
    private static function interestIn(RepaymentPlan $plan, int $month): int
    {
        foreach ($plan->schedule as $row) {
            if ($row->month === $month) {
                return $row->interest->cents();
            }
        }

        self::fail(\sprintf('Der Plan hat keinen Monat %d.', $month));
    }

    private static function lastOf(RepaymentPlan $plan): Repayment
    {
        return $plan->last();
    }
}
