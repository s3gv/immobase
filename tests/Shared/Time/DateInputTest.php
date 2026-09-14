<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Time;

use App\Shared\Time\DateInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ein Datum, das niemand so eingegeben hat, wird keins.
 *
 * Der Fall, der diesen Test ausgeloest hat: `new DateTimeImmutable('2026-02-30')`
 * rechnet stillschweigend um und liefert den 2. Maerz. Gespeichert stuende
 * dann ein Tag, den niemand getippt hat — und niemand merkt es.
 */
final class DateInputTest extends TestCase
{
    public function testReadsAnIsoDate(): void
    {
        $date = DateInput::orNull(self::withDate('2026-04-01'), 'day');

        self::assertSame('2026-04-01', $date?->format('Y-m-d'));
    }

    /** Ohne Uhrzeit: ein Mietbeginn gilt ab einem Tag, nicht ab einem Moment. */
    public function testTheTimeIsReset(): void
    {
        self::assertSame('00:00:00', DateInput::orNull(self::withDate('2026-04-01'), 'day')?->format('H:i:s'));
    }

    public function testAnEmptyFieldIsNoDate(): void
    {
        self::assertNull(DateInput::orNull(self::withDate(''), 'day'));
        self::assertNull(DateInput::orNull(self::withDate('   '), 'day'));
        self::assertNull(DateInput::orNull(new Request(), 'day'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'den gibt es nicht' => ['2026-02-30'];
        yield 'Monat dreizehn' => ['2026-13-01'];
        yield 'Tag zweiunddreissig' => ['2026-01-32'];
        yield 'deutsches Format' => ['01.04.2026'];
        yield 'ohne Tag' => ['2026-04'];
        yield 'Buchstaben' => ['morgen'];
        yield 'mit Uhrzeit' => ['2026-04-01 12:00'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsNotADay(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateInput::orNull(self::withDate($raw), 'day');
    }

    private static function withDate(string $raw): Request
    {
        return new Request(request: ['day' => $raw]);
    }
}
