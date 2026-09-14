<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Application;

use App\Module\Tenancy\Application\ScheduleHousehold;
use App\Module\Tenancy\Domain\StepNotAllowed;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\Term;
use App\Tests\Module\Tenancy\Fixture\InMemoryTenancyRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Dieselben zwei Regeln wie bei der Mietstaffel — und eine Zahl, die zaehlt.
 */
final class ScheduleHouseholdTest extends TestCase
{
    public function testAnEntryBeforeTheStartOfTheTenancyIsRefused(): void
    {
        $tenancy = self::tenancy('2026-04-01');

        $this->expectException(StepNotAllowed::class);

        self::schedule()->add($tenancy, new DateTimeImmutable('2026-03-01'), 2);
    }

    public function testTwoEntriesOnTheSameDayAreRefused(): void
    {
        $schedule = self::schedule();
        $tenancy = self::tenancy('2026-04-01');
        $schedule->add($tenancy, new DateTimeImmutable('2026-04-01'), 2);

        $this->expectException(StepNotAllowed::class);

        $schedule->add($tenancy, new DateTimeImmutable('2026-04-01'), 3);
    }

    public function testANegativeNumberOfPeopleIsRefused(): void
    {
        $tenancy = self::tenancy('2026-04-01');

        $this->expectException(InvalidArgumentException::class);

        self::schedule()->add($tenancy, new DateTimeImmutable('2026-04-01'), -1);
    }

    /**
     * Der alte Stand bleibt stehen, wenn ein neuer dazukommt.
     *
     * Das ist der ganze Sinn der Staffel: 2027 wohnen drei Personen dort, und
     * die Abrechnung fuer 2026 rechnet trotzdem mit zwei.
     */
    public function testAnEarlierEntrySurvivesALaterOne(): void
    {
        $schedule = self::schedule();
        $tenancy = self::tenancy('2026-01-01');
        $schedule->add($tenancy, new DateTimeImmutable('2026-01-01'), 2);
        $schedule->add($tenancy, new DateTimeImmutable('2027-01-01'), 3);

        $household = $tenancy->household();
        self::assertSame(2, $household->peopleOn(new DateTimeImmutable('2026-07-01')));
        self::assertSame(3, $household->peopleOn(new DateTimeImmutable('2027-07-01')));
    }

    /** Vor dem ersten Eintrag ist nichts erfasst — und das ist nicht null Personen. */
    public function testBeforeTheFirstEntryNothingIsRecorded(): void
    {
        $tenancy = self::tenancy('2026-01-01');
        self::schedule()->add($tenancy, new DateTimeImmutable('2026-06-01'), 2);

        self::assertNull($tenancy->household()->peopleOn(new DateTimeImmutable('2026-01-15')));
    }

    private static function schedule(): ScheduleHousehold
    {
        return new ScheduleHousehold(new InMemoryTenancyRepository());
    }

    private static function tenancy(string $startsOn): Tenancy
    {
        $tenancy = new Tenancy(30001, 'einheit-1');
        $tenancy->runFor(Term::of(new DateTimeImmutable($startsOn), null, null, null));

        return $tenancy;
    }
}
