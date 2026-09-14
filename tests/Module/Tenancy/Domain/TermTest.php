<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Domain;

use App\Module\Tenancy\Domain\Term;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Die Laufzeit muss eine sein.
 */
final class TermTest extends TestCase
{
    /**
     * Ein Ende vor dem Beginn ist kein Zeitraum, sondern ein Zahlendreher —
     * und die Staffel, die auf dem Beginn aufsetzt, haette danach keinen
     * Boden mehr.
     */
    public function testAnEndBeforeTheStartIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Term::of(self::day('2026-04-01'), self::day('2026-03-31'), null, null);
    }

    /** Am selben Tag zu beginnen und zu enden ist erlaubt — ein Tag Miete. */
    public function testTheSameDayIsFine(): void
    {
        $term = Term::of(self::day('2026-04-01'), self::day('2026-04-01'), null, null);

        self::assertFalse($term->isOpenEnded());
    }

    /** Ohne Ende ist es unbefristet — der Regelfall bei Wohnraum. */
    public function testWithoutAnEndItIsOpenEnded(): void
    {
        self::assertTrue(Term::of(self::day('2026-04-01'), null, null, null)->isOpenEnded());
        self::assertTrue(Term::unknown()->isOpenEnded());
    }

    public function testANegativeNoticePeriodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Term::of(self::day('2026-04-01'), null, null, -3);
    }

    /**
     * Und keine, die in kein SMALLINT passt: ohne Grenze endete sie als
     * Datenbankfehler statt als Hinweis am Feld.
     */
    public function testAnAbsurdNoticePeriodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Term::of(self::day('2026-04-01'), null, null, 999999);
    }

    /** Die Uebergabe darf vor dem Mietbeginn liegen — Schlüssel kommen früher. */
    public function testTheHandoverMayBeBeforeTheStart(): void
    {
        $term = Term::of(self::day('2026-04-01'), null, self::day('2026-03-28'), 3);

        self::assertSame('2026-03-28', $term->handedOverOn()?->format('Y-m-d'));
        self::assertSame(3, $term->noticePeriodMonths());
    }

    private static function day(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day);
    }
}
