<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Application;

use App\Module\Plugin\Application\PluginPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginPathTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function plain(): iterable
    {
        yield 'Menuepunkt' => ['berichte', '/berichte'];
        yield 'Unterseite' => ['berichte/objekt/4711', '/berichte/objekt/4711'];
        yield 'Schraegstrich am Ende' => ['berichte/', '/berichte/'];
        yield 'Punkt im Namen' => ['berichte/export.csv', '/berichte/export.csv'];
        yield 'Wurzel' => ['', '/'];
    }

    #[DataProvider('plain')]
    public function testAPlainPathPassesUnchanged(string $given, string $expected): void
    {
        self::assertSame($expected, PluginPath::orNull($given));
    }

    /** @return iterable<string, array{string}> */
    public static function rewritable(): iterable
    {
        yield 'Punkt-Punkt' => ['berichte/../intern'];
        yield 'Punkt' => ['berichte/./intern'];
        yield 'Punkt-Punkt am Ende' => ['berichte/..'];
        yield 'leeres Segment' => ['berichte//intern'];
        yield 'Backslash' => ['berichte\\..\\intern'];
        yield 'kodiert' => ['berichte/%2e%2e/intern'];
        yield 'Frage' => ['berichte?x=1'];
        yield 'Doppelkreuz' => ['berichte#x'];
        yield 'Zeilenumbruch' => ["berichte\nHost: x"];
        yield 'Leerzeichen' => ['berichte intern'];
    }

    #[DataProvider('rewritable')]
    public function testAPathThatCouldChangeOnTheWayIsRefused(string $given): void
    {
        self::assertNull(PluginPath::orNull($given));
    }
}
