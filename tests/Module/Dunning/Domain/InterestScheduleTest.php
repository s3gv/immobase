<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Dunning\Domain;

use App\Module\Dunning\Domain\Arrears;
use App\Module\Dunning\Domain\BaseRate;
use App\Module\Dunning\Domain\BaseRates;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimStep;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Dunning\Domain\Debtor;
use App\Module\Dunning\Domain\InterestSchedule;
use App\Module\Dunning\Domain\Source;
use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Die Verzugszinsen.
 *
 * Die Staffel ist das Ergebnis und nicht ein Zwischenschritt: wer eine
 * Zinsforderung bestreitet, bestreitet einen ihrer vier Werte. Darum wird
 * hier nicht nur die Summe geprueft, sondern jede Zeile.
 */
final class InterestScheduleTest extends TestCase
{
    /**
     * Ein halbes Jahr auf einem Satz — die einfachste Rechnung.
     *
     * 450,00 € zu 6,52 % (1,52 % Basiszins plus fuenf Punkte) fuer 73 Tage:
     * 45000 × 652 × 73 / (10000 × 365) = 586,8 Cent, kaufmaennisch 5,87 €.
     */
    public function testOneRateOneYear(): void
    {
        $schedule = self::scheduleFrom('2026-07-01', '2026-09-12', Money::fromCents(45000));

        self::assertCount(1, $schedule->segments);
        self::assertSame(73, $schedule->segments[0]->days);
        self::assertSame(152, $schedule->segments[0]->baseRateBps);
        self::assertSame(652, $schedule->segments[0]->rateBps());
        self::assertSame(587, $schedule->total()->cents());
    }

    /** Am Halbjahreswechsel wird geschnitten — der Satz aendert sich. */
    public function testTheScheduleCutsAtTheHalfYear(): void
    {
        $schedule = self::scheduleFrom('2026-03-04', '2026-09-12', Money::fromCents(45000));

        self::assertCount(2, $schedule->segments);
        self::assertSame('2026-06-30', $schedule->segments[0]->until->format('Y-m-d'));
        self::assertSame(127, $schedule->segments[0]->baseRateBps, 'Der alte Satz bis Ende Juni');
        self::assertSame('2026-07-01', $schedule->segments[1]->from->format('Y-m-d'));
        self::assertSame(152, $schedule->segments[1]->baseRateBps, 'Der neue ab Juli');
    }

    /**
     * Am Jahreswechsel auch — dort aendert sich der Nenner.
     *
     * Der Zeitraum liegt bewusst dort, wo der Basiszinssatz **nicht**
     * wechselt: von Mitte 2016 bis Ende 2022 lag er unveraendert bei
     * −0,88 %. Ein Test ueber einen Jahreswechsel, an dem auch der Satz
     * springt, bewiese nur, dass der Satzwechsel schneidet.
     *
     * 2020 ist ein Schaltjahr mit 366 Tagen, 2019 hat 365. Wer ueber den
     * Jahreswechsel mit einem Nenner rechnet, rechnet ein Jahr lang leicht
     * daneben.
     */
    public function testTheScheduleCutsAtTheTurnOfTheYear(): void
    {
        $schedule = self::scheduleFrom('2019-11-01', '2020-02-01', Money::fromCents(100000));

        self::assertCount(2, $schedule->segments);
        self::assertSame('2019-12-31', $schedule->segments[0]->until->format('Y-m-d'));
        self::assertSame(61, $schedule->segments[0]->days, 'November und Dezember');
        self::assertSame('2020-01-01', $schedule->segments[1]->from->format('Y-m-d'));
        self::assertSame(
            $schedule->segments[0]->baseRateBps,
            $schedule->segments[1]->baseRateBps,
            'Derselbe Satz — geschnitten wird allein wegen des Nenners',
        );

        // 1.000,00 € zu 4,12 % (−0,88 % plus fuenf Punkte):
        // 61 von 365 Tagen sind 688,55 Cent, kaufmaennisch 6,89 €;
        // 31 von 366 Tagen sind 348,96 Cent, also 3,49 €. Mit einem
        // einzigen Nenner kaeme der zweite Abschnitt anders heraus.
        self::assertSame(689, $schedule->segments[0]->interest->cents());
        self::assertSame(31, $schedule->segments[1]->days);
        self::assertSame(349, $schedule->segments[1]->interest->cents());
    }

    /** Die Summe ist die Summe der gedruckten Zeilen. */
    public function testTheTotalIsTheSumOfTheLines(): void
    {
        $schedule = self::scheduleFrom('2023-05-01', '2026-09-12', Money::fromCents(123456));

        $lines = Money::zero();

        foreach ($schedule->segments as $segment) {
            $lines = $lines->plus($segment->interest);
        }

        self::assertSame($lines->cents(), $schedule->total()->cents());
        self::assertGreaterThan(4, \count($schedule->segments), 'Ueber drei Jahre sind es viele Abschnitte');
    }

    /**
     * Ein negativer Basiszinssatz ergibt keinen negativen Zins.
     *
     * Von Mitte 2016 bis Ende 2022 lag er bei −0,88 %. Fuenf Punkte darauf
     * sind 4,12 % — positiv. Aber die Rechnung deckelt auch dort, wo der
     * Satz rechnerisch unter null fiele.
     */
    public function testANegativeBaseRateNeverOwesNegativeInterest(): void
    {
        $schedule = self::scheduleFrom('2020-01-01', '2020-12-31', Money::fromCents(100000), pointsBps: 0);

        self::assertSame(0, $schedule->total()->cents());

        foreach ($schedule->segments as $segment) {
            self::assertFalse($segment->interest->isNegative());
        }
    }

    /** Eine Teilzahlung mindert den Betrag ab ihrem Tag. */
    public function testAPartialPaymentLowersTheAmount(): void
    {
        $claim = self::claim('2026-01-01');
        new ClaimStep($claim, new DateTimeImmutable('2026-01-02'), Money::fromCents(100000));
        $claim->nowOpen(Money::fromCents(40000), new DateTimeImmutable('2026-04-01'));

        $schedule = InterestSchedule::of(
            $claim->steps(),
            self::rates(),
            500,
            new DateTimeImmutable('2026-06-30'),
        );

        self::assertCount(2, $schedule->segments);
        self::assertSame(100000, $schedule->segments[0]->amount->cents());
        self::assertSame('2026-03-31', $schedule->segments[0]->until->format('Y-m-d'));
        self::assertSame(40000, $schedule->segments[1]->amount->cents());
    }

    /** Was erledigt ist, verzinst sich nicht weiter. */
    public function testASettledClaimStopsAccruing(): void
    {
        $claim = self::claim('2026-01-01');
        new ClaimStep($claim, new DateTimeImmutable('2026-01-02'), Money::fromCents(100000));
        $claim->settle(new DateTimeImmutable('2026-03-01'));

        $schedule = InterestSchedule::of(
            $claim->steps(),
            self::rates(),
            500,
            new DateTimeImmutable('2026-12-31'),
        );

        self::assertCount(1, $schedule->segments);
        self::assertSame('2026-02-28', $schedule->segments[0]->until->format('Y-m-d'));
    }

    /**
     * Ohne Basiszinssatz wird nicht geschaetzt.
     *
     * Eine Luecke in der Reihe laesst den Abschnitt weg, statt mit null
     * Prozent zu rechnen — „null Prozent" waere eine Behauptung, „nichts
     * eingetragen" ist die Wahrheit.
     */
    public function testAMissingBaseRateLeavesTheSegmentOut(): void
    {
        $claim = self::claim('2014-01-01');
        new ClaimStep($claim, new DateTimeImmutable('2014-01-02'), Money::fromCents(100000));

        $schedule = InterestSchedule::of(
            $claim->steps(),
            self::rates(),
            500,
            new DateTimeImmutable('2014-12-31'),
        );

        self::assertTrue($schedule->isEmpty());
        self::assertSame(0, $schedule->total()->cents());
    }

    private static function scheduleFrom(
        string $from,
        string $until,
        Money $amount,
        int $pointsBps = 500,
    ): InterestSchedule {
        $claim = self::claim($from);
        new ClaimStep($claim, new DateTimeImmutable($from), $amount);

        return InterestSchedule::of($claim->steps(), self::rates(), $pointsBps, new DateTimeImmutable($until));
    }

    private static function claim(string $from): Claim
    {
        return new Claim(
            Debtor::of(Uuid::v4(), false),
            Source::entered(CreditorIdentity::theCommunityOf(Uuid::v4()), null),
            Arrears::of(new DateTimeImmutable($from), new DateTimeImmutable($from)),
        );
    }

    /** Die Reihe der Bundesbank, so wie die Migration sie vorbelegt. */
    private static function rates(): BaseRates
    {
        $rates = [];

        foreach ([
            '2016-01-01' => -83, '2016-07-01' => -88, '2023-01-01' => 162, '2023-07-01' => 312,
            '2024-01-01' => 362, '2024-07-01' => 337, '2025-01-01' => 227, '2025-07-01' => 127,
            '2026-07-01' => 152,
        ] as $day => $bps) {
            $rates[] = new BaseRate(new DateTimeImmutable($day), $bps);
        }

        return BaseRates::of($rates);
    }
}
