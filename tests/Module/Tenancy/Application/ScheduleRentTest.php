<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Application;

use App\Module\Tenancy\Application\ScheduleRent;
use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\StepNotAllowed;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\Term;
use App\Shared\Money\Money;
use App\Tests\Module\Tenancy\Fixture\InMemoryTenancyRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Zwei Regeln halten die Staffel lesbar.
 */
final class ScheduleRentTest extends TestCase
{
    public function testAStepBeforeTheStartOfTheTenancyIsRefused(): void
    {
        $tenancy = self::tenancy('2026-04-01');

        $this->expectException(StepNotAllowed::class);

        // Sonst gaebe es eine Miete fuer eine Zeit ohne Mietverhaeltnis.
        self::schedule()->add($tenancy, new DateTimeImmutable('2026-03-01'), self::rent(650));
    }

    public function testAStepOnTheFirstDayIsFine(): void
    {
        $tenancy = self::tenancy('2026-04-01');

        $step = self::schedule()->add($tenancy, new DateTimeImmutable('2026-04-01'), self::rent(650));

        self::assertSame('2026-04-01', $step->startsOn()->format('Y-m-d'));
    }

    public function testTwoStepsOnTheSameDayAreRefused(): void
    {
        $schedule = self::schedule();
        $tenancy = self::tenancy('2026-04-01');
        $schedule->add($tenancy, new DateTimeImmutable('2026-04-01'), self::rent(650));

        $this->expectException(StepNotAllowed::class);

        // Sonst waere nicht entscheidbar, welche gilt.
        $schedule->add($tenancy, new DateTimeImmutable('2026-04-01'), self::rent(700));
    }

    /** Eine Stufe auf ihr eigenes Datum zu speichern ist keine Doppelung. */
    public function testAStepMayKeepItsOwnDate(): void
    {
        $schedule = self::schedule();
        $tenancy = self::tenancy('2026-04-01');
        $step = $schedule->add($tenancy, new DateTimeImmutable('2026-04-01'), self::rent(650));

        $schedule->change($step, new DateTimeImmutable('2026-04-01'), self::rent(700));

        self::assertSame(70000, $step->rent()->base->cents());
    }

    /** Ohne Mietbeginn gibt es nichts, wovor eine Stufe liegen koennte. */
    public function testWithoutAStartAnyDateIsAllowed(): void
    {
        $tenancy = new Tenancy(30001, 'einheit-1');

        $step = self::schedule()->add($tenancy, new DateTimeImmutable('2020-01-01'), self::rent(650));

        self::assertSame('2020-01-01', $step->startsOn()->format('Y-m-d'));
    }

    public function testAStepCanBeDropped(): void
    {
        $schedule = self::schedule();
        $tenancy = self::tenancy('2026-04-01');
        $step = $schedule->add($tenancy, new DateTimeImmutable('2026-04-01'), self::rent(650));

        $schedule->drop($step);

        self::assertTrue($tenancy->schedule()->isEmpty());
    }

    private static function schedule(): ScheduleRent
    {
        return new ScheduleRent(new InMemoryTenancyRepository());
    }

    private static function tenancy(string $startsOn): Tenancy
    {
        $tenancy = new Tenancy(30001, 'einheit-1');
        $tenancy->runFor(Term::of(new DateTimeImmutable($startsOn), null, null, null));

        return $tenancy;
    }

    private static function rent(int $base): Rent
    {
        return Rent::of(Money::fromCents($base * 100), Money::zero(), Money::zero(), Money::zero());
    }
}
