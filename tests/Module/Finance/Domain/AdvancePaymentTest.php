<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Finance\Domain;

use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\DueDates;
use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Drei Zustaende, eine Zahl.
 *
 * An `received()` haengt jede Abrechnung. Die Unterscheidung zwischen „nichts
 * gezahlt" und „zur Haelfte gezahlt" ist der Grund, warum es den Teilbetrag
 * ueberhaupt gibt.
 */
final class AdvancePaymentTest extends TestCase
{
    public function testAFreshEntryCountsAsPaid(): void
    {
        $payment = self::payment(25000);

        self::assertTrue($payment->isSettled());
        self::assertNull($payment->part());
        self::assertSame(25000, $payment->received()->cents());
    }

    /** Umschalten ohne Betrag heisst: gar nichts gekommen. */
    public function testMissedWithoutAnAmountMeansNothingArrived(): void
    {
        $payment = self::payment(25000);

        $payment->missed(null);

        self::assertFalse($payment->isSettled());
        self::assertNull($payment->part());
        self::assertTrue($payment->received()->isZero());
    }

    public function testAPartPaymentCountsWithItsAmount(): void
    {
        $payment = self::payment(25000);

        $payment->missed(Money::fromCents(10000));

        self::assertSame(10000, $payment->received()->cents());
        self::assertSame(10000, $payment->part()?->cents());
    }

    /**
     * Zurueckschalten loescht den Teilbetrag.
     *
     * Bliebe er liegen, kaeme er beim naechsten Umschalten als Zahl wieder
     * hoch, die niemand mehr eingegeben hat — und die Abrechnung rechnete
     * damit.
     */
    public function testSettlingAgainForgetsThePart(): void
    {
        $payment = self::payment(25000);
        $payment->missed(Money::fromCents(10000));

        $payment->settle();

        self::assertNull($payment->part());
        self::assertSame(25000, $payment->received()->cents());
    }

    /** Zieht die Staffel nach, folgt der Sollbetrag — und mit ihm die Zahlung. */
    public function testTheExpectedAmountFollowsTheSchedule(): void
    {
        $payment = self::payment(25000);

        $payment->expect(Money::fromCents(27500));

        self::assertSame(27500, $payment->received()->cents(), 'Bezahlt heißt: der neue Sollbetrag');
    }

    /** Eine Teilzahlung bleibt, was sie war — sie ist eine Beobachtung. */
    public function testAPartPaymentDoesNotFollowTheSchedule(): void
    {
        $payment = self::payment(25000);
        $payment->missed(Money::fromCents(10000));

        $payment->expect(Money::fromCents(27500));

        self::assertSame(10000, $payment->received()->cents());
    }

    /** Monatlich sind zwölf Fälligkeiten, vierteljährlich vier, jährlich eine. */
    public function testTheRhythmComesFromTheInterval(): void
    {
        $begins = new DateTimeImmutable('2026-01-01');

        self::assertCount(12, DueDates::inYear($begins, Interval::Monthly));
        self::assertCount(4, DueDates::inYear($begins, Interval::Quarterly));
        self::assertCount(1, DueDates::inYear($begins, Interval::Annually));
    }

    /**
     * Der Rhythmus zaehlt ab dem Wirtschaftsjahr, nicht ab dem Kalenderjahr.
     *
     * Bei einem Beginn am 1. Juli sind die Quartale Juli, Oktober, Januar und
     * April — nicht Januar, April, Juli, Oktober.
     */
    public function testTheRhythmCountsFromTheFiscalYear(): void
    {
        $days = DueDates::inYear(new DateTimeImmutable('2026-07-01'), Interval::Quarterly);

        self::assertSame(
            ['2026-07-01', '2026-10-01', '2027-01-01', '2027-04-01'],
            array_map(static fn (DateTimeImmutable $day): string => $day->format('Y-m-d'), $days),
        );
    }

    private static function payment(int $cents): AdvancePayment
    {
        return new AdvancePayment(
            Uuid::v4(),
            AdvanceKind::HouseMoney,
            2026,
            new DateTimeImmutable('2026-01-01'),
            Money::fromCents($cents),
        );
    }
}
