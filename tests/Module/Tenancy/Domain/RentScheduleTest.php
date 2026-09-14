<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Domain;

use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Tenancy;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Was heute gilt — und was erst noch kommt.
 */
final class RentScheduleTest extends TestCase
{
    private const string TODAY = '2026-06-15';

    /**
     * Die letzte Stufe mit Datum <= heute, nicht die letzte ueberhaupt.
     *
     * Das ist der ganze Sinn der Staffel: eine Erhoehung, die erst naechstes
     * Jahr greift, steht schon in der Liste und gilt noch nicht. Wer nur die
     * letzte naehme, berechnete ab dem Tag der Erfassung zu viel.
     */
    public function testTodayIsTheLastStepThatHasStarted(): void
    {
        $tenancy = self::tenancy();
        self::step($tenancy, '2025-01-01', 600);
        self::step($tenancy, '2026-01-01', 650);
        self::step($tenancy, '2027-01-01', 700);

        $rent = $tenancy->schedule()->rentOn(new DateTimeImmutable(self::TODAY));

        self::assertNotNull($rent);
        self::assertSame(65000, $rent->base->cents());
    }

    /** Die Reihenfolge der Erfassung ist gleichgueltig. */
    public function testTheOrderOfEntryDoesNotMatter(): void
    {
        $tenancy = self::tenancy();
        self::step($tenancy, '2027-01-01', 700);
        self::step($tenancy, '2025-01-01', 600);
        self::step($tenancy, '2026-01-01', 650);

        $rent = $tenancy->schedule()->rentOn(new DateTimeImmutable(self::TODAY));

        self::assertNotNull($rent);
        self::assertSame(65000, $rent->base->cents());
    }

    /** Vor der ersten Stufe gilt keine — und nicht die erste. */
    public function testBeforeTheFirstStepNothingApplies(): void
    {
        $tenancy = self::tenancy();
        self::step($tenancy, '2027-01-01', 700);

        self::assertNull($tenancy->schedule()->rentOn(new DateTimeImmutable(self::TODAY)));
    }

    /** Am Tag des Wechsels gilt schon die neue Stufe. */
    public function testTheStepAppliesOnItsFirstDay(): void
    {
        $tenancy = self::tenancy();
        self::step($tenancy, '2026-01-01', 600);
        self::step($tenancy, self::TODAY, 650);

        $rent = $tenancy->schedule()->rentOn(new DateTimeImmutable(self::TODAY));

        self::assertNotNull($rent);
        self::assertSame(65000, $rent->base->cents());
    }

    /** Die naechste Aenderung — fuer den Hinweis auf der Mietseite. */
    public function testTheNextChangeIsTheOneAfterToday(): void
    {
        $tenancy = self::tenancy();
        self::step($tenancy, '2026-01-01', 600);
        self::step($tenancy, '2027-01-01', 700);

        $next = $tenancy->schedule()->nextAfter(new DateTimeImmutable(self::TODAY));

        self::assertNotNull($next);
        self::assertSame('2027-01-01', $next->startsOn()->format('Y-m-d'));
    }

    public function testWithoutAnyStepThereIsNoNextChange(): void
    {
        self::assertNull(self::tenancy()->schedule()->nextAfter(new DateTimeImmutable(self::TODAY)));
        self::assertTrue(self::tenancy()->schedule()->isEmpty());
    }

    private static function tenancy(): Tenancy
    {
        return new Tenancy(30001, 'einheit-1');
    }

    private static function step(Tenancy $tenancy, string $day, int $base): void
    {
        new RentStep($tenancy, new DateTimeImmutable($day), Rent::of(
            Money::fromCents($base * 100),
            Money::zero(),
            Money::zero(),
            Money::zero(),
        ));
    }
}
