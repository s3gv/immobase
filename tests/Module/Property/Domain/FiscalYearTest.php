<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Domain;

use App\Module\Property\Domain\FiscalYear;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Das Wirtschaftsjahr: eine Regel, die fuer jedes Jahr gilt.
 */
final class FiscalYearTest extends TestCase
{
    public function testTheCalendarYearRunsFromJanuaryToDecember(): void
    {
        $year = FiscalYear::calendar();

        self::assertSame('2026-01-01', $year->beginsIn(2026)->format('Y-m-d'));
        self::assertSame('2026-12-31', $year->endsIn(2026)->format('Y-m-d'));
    }

    /** Ein abweichendes Wirtschaftsjahr laeuft von Beginn zu Beginn. */
    public function testAnOffCalendarYearRunsToTheDayBeforeTheNextStart(): void
    {
        $year = FiscalYear::beginningOn(1, 7);

        self::assertSame('2026-07-01', $year->beginsIn(2026)->format('Y-m-d'));
        self::assertSame('2027-06-30', $year->endsIn(2026)->format('Y-m-d'));
    }

    /**
     * Benannt wird ein Wirtschaftsjahr nach dem Jahr, in dem es beginnt.
     *
     * Der 30. Juni 2027 liegt bei einem Beginn am 1. Juli im Wirtschaftsjahr
     * 2026 — und das ist die Frage, die jede Abrechnung zuerst stellt.
     */
    public function testADayInJuneBelongsToThePreviousYear(): void
    {
        $year = FiscalYear::beginningOn(1, 7);

        self::assertSame(2026, $year->yearOf(new DateTimeImmutable('2027-06-30')));
        self::assertSame(2027, $year->yearOf(new DateTimeImmutable('2027-07-01')));
    }

    public function testInTheCalendarYearTheDayKeepsItsYear(): void
    {
        $year = FiscalYear::calendar();

        self::assertSame(2026, $year->yearOf(new DateTimeImmutable('2026-12-31')));
        self::assertSame(2027, $year->yearOf(new DateTimeImmutable('2027-01-01')));
    }

    /**
     * Ein Tag, den es nicht in jedem Monat gibt, ist keine Regel.
     *
     * „Ab dem 31." waere in elf von zwoelf Faellen eine Frage ohne Antwort,
     * und der 29. Februar eine, die nur alle vier Jahre eine hat.
     */
    public function testADayBeyondTheTwentyEighthIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FiscalYear::beginningOn(31, 1);
    }

    public function testAMonthOutsideTheYearIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FiscalYear::beginningOn(1, 13);
    }
}
