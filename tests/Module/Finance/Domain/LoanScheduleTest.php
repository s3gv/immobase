<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Finance\Domain;

use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanEvent;
use App\Module\Finance\Domain\LoanEventKind;
use App\Module\Finance\Domain\LoanSchedule;
use App\Module\Finance\Domain\LoanTerms;
use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Der Tilgungsplan trifft den Kalender.
 *
 * {@see \App\Shared\Money\RepaymentPlan} rechnet in Monaten und weiss nichts
 * von Daten. Hier kommt der Kalender dazu — und genau an dieser Naht sitzen
 * die Fehler: ein Jahr, das seine Januarrate verliert, sieht auf den ersten
 * Blick richtig aus und sammelt eine Rate zu wenig ein.
 */
final class LoanScheduleTest extends TestCase
{
    /**
     * Zwoelf Raten je vollem Jahr — auch im ersten Jahr danach.
     *
     * Die Zusicherung, an der die Wirtschaftsplanzeile haengt: was ein Jahr
     * kostet, ist die Summe seiner Raten. Gezaehlt wird ab dem Stand an
     * Silvester davor; wer vom ersten Januar aus zaehlt, hat die Januarrate
     * schon mitgezaehlt und laesst sie aus.
     */
    public function testAFullYearCarriesTwelveInstalments(): void
    {
        $schedule = LoanSchedule::of(self::loan(12000000, 390, 85000, '2026-04-01'));
        $burden = $schedule->burdenIn(2027);

        self::assertSame(
            1020000,
            $burden['interest']->plus($burden['principal'])->cents(),
            'Zwölf Raten zu 850 Euro',
        );
    }

    /** Das Anfangsjahr traegt nur die Raten ab der ersten Faelligkeit. */
    public function testTheFirstYearOnlyCarriesTheMonthsThatRan(): void
    {
        $schedule = LoanSchedule::of(self::loan(12000000, 390, 85000, '2026-04-01'));
        $burden = $schedule->burdenIn(2026);

        self::assertSame(765000, $burden['interest']->plus($burden['principal'])->cents(), 'April bis Dezember');
    }

    /** Das letzte Jahr traegt die letzte Rate — und nicht null. */
    public function testTheClosingYearCarriesTheFinalInstalment(): void
    {
        $loan = self::loan(12000000, 390, 85000, '2026-04-01');
        $schedule = LoanSchedule::of($loan);
        $last = $schedule->endsOn();
        $burden = $schedule->burdenIn((int) $last->format('Y'));

        self::assertFalse($burden['interest']->plus($burden['principal'])->isZero(), 'Das Schlussjahr ist nicht leer');
        self::assertSame(0, $schedule->debtAt($last)->cents(), 'Und danach steht nichts mehr offen');
    }

    /**
     * Vor der ersten Rate ist nichts offen.
     *
     * Die Zusicherung, an der der Vermoegensbericht haengt: ein Darlehen, das
     * noch nicht laeuft, steht in keinem Bericht der Jahre davor. Die volle
     * Summe dort auszuweisen waere ein Fehler um ein ganzes Darlehen.
     */
    public function testBeforeTheFirstInstalmentNothingIsOwedYet(): void
    {
        $schedule = LoanSchedule::of(self::loan(6000000, 275, 57247, '2027-01-15'));

        self::assertSame(0, $schedule->debtAt(new DateTimeImmutable('2026-12-31'))->cents());
        self::assertSame(0, $schedule->debtAt(new DateTimeImmutable('2027-01-14'))->cents(), 'Auch am Tag davor');
        // Erste Rate 572,47 €, davon 137,50 € Zins — getilgt sind 434,97 €.
        self::assertSame(
            6000000 - 43497,
            $schedule->debtAt(new DateTimeImmutable('2027-01-15'))->cents(),
            'Ab der ersten Rate steht die Summe abzüglich ihrer Tilgung offen',
        );
    }

    /** Die Sondertilgung zaehlt zur Tilgung ihres Jahres — abgeflossen ist sie auch. */
    public function testAnExtraPaymentCountsTowardsThePrincipalOfItsYear(): void
    {
        $loan = self::loan(12000000, 390, 85000, '2026-04-01');
        new LoanEvent($loan, LoanEventKind::ExtraPayment, new DateTimeImmutable('2028-06-01'), Money::fromCents(1000000), null);
        $burden = LoanSchedule::of($loan)->burdenIn(2028);

        self::assertSame(
            12 * 85000 + 1000000,
            $burden['interest']->plus($burden['principal'])->cents(),
            'Zwölf Raten und die Sondertilgung obendrauf',
        );
    }

    /** Gezahlt wird am selben Tag des Monats wie die erste Rate. */
    public function testTheMonthCounterFollowsTheDayOfTheFirstInstalment(): void
    {
        $loan = self::loan(6000000, 275, 57247, '2027-01-15');

        self::assertSame(0, LoanSchedule::monthAt($loan, new DateTimeImmutable('2027-01-14')));
        self::assertSame(1, LoanSchedule::monthAt($loan, new DateTimeImmutable('2027-01-15')));
        self::assertSame(1, LoanSchedule::monthAt($loan, new DateTimeImmutable('2027-02-14')), 'Der Tag davor zählt nicht');
        self::assertSame(2, LoanSchedule::monthAt($loan, new DateTimeImmutable('2027-02-15')));
    }

    private static function loan(int $amount, int $rateBps, int $payment, string $startsOn): Loan
    {
        return new Loan(50001, Uuid::v4(), LoanTerms::withPayment(
            Money::fromCents($amount),
            $rateBps,
            new DateTimeImmutable($startsOn),
            Money::fromCents($payment),
        ));
    }
}
