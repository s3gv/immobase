<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Time;

use App\Shared\Time\Dates;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Ein Datum wird gelesen, wie die Sprache es schreibt.
 */
final class DatesTest extends TestCase
{
    public function testGermanWritesItWithDots(): void
    {
        self::assertSame('31.12.2026', Dates::format(new DateTimeImmutable('2026-12-31'), 'de'));
        self::assertSame('01.01.2026', Dates::format(new DateTimeImmutable('2026-01-01'), 'de'));
    }

    /**
     * Der Monat steht ausgeschrieben: „12/31" und „31/12" sind beide
     * gelaeufig, widersprechen sich, und am Bildschirm steht nicht dabei,
     * welche Variante gerade gilt.
     */
    public function testEnglishWritesTheMonthOut(): void
    {
        self::assertSame('31 Dec 2026', Dates::format(new DateTimeImmutable('2026-12-31'), 'en'));
        self::assertSame('1 Jan 2026', Dates::format(new DateTimeImmutable('2026-01-01'), 'en'));
    }

    public function testAnUnknownLanguageFollowsGerman(): void
    {
        self::assertSame('31.12.2026', Dates::format(new DateTimeImmutable('2026-12-31'), 'fr'));
    }
}
