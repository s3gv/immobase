<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Background;

use App\Shared\Background\BackgroundRound;
use App\Tests\Shared\Background\Fixture\CountingJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Der Durchgang durch die Hintergrundarbeiten.
 *
 * Zwei Zusicherungen: jede Arbeit laeuft in ihrem Takt, nicht oefter — und
 * **eine, die scheitert, haelt die anderen nicht auf.** Ein Mailserver, der
 * nicht antwortet, soll kein Plugin am Neustart hindern.
 */
final class BackgroundRoundTest extends TestCase
{
    public function testEachJobRunsInItsOwnRhythm(): void
    {
        $clock = new MockClock('2026-09-14 09:00:00');
        $fast = new CountingJob('plugins', 5);
        $slow = new CountingJob('portal', 60);
        $round = new BackgroundRound([$fast, $slow], $clock);

        $round();
        $clock->sleep(5);
        $round();
        $clock->sleep(5);
        $round();

        self::assertSame(3, $fast->runs, 'Alle fünf Sekunden');
        self::assertSame(1, $slow->runs, 'Und nicht öfter als einmal je Minute');

        $clock->sleep(50);
        $round();

        self::assertSame(2, $slow->runs, 'Nach der Minute wieder');
    }

    public function testAFailingJobDoesNotStopTheOthers(): void
    {
        $broken = new CountingJob('portal', 60, fails: true);
        $plugins = new CountingJob('plugins', 5);
        $round = new BackgroundRound([$broken, $plugins], new MockClock());

        $failures = $round();

        self::assertSame(1, $plugins->runs, 'Die Plugins liefen trotzdem');
        self::assertCount(1, $failures);
        self::assertStringStartsWith('portal: ', $failures[0], 'Und der Fehler steht mit Namen da');
    }
}
