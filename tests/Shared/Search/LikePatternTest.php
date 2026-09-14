<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Search;

use App\Shared\Search\LikePattern;
use PHPUnit\Framework\TestCase;

final class LikePatternTest extends TestCase
{
    public function testWildcardsAreSearchedForAsCharacters(): void
    {
        self::assertSame('%100 \\%%', LikePattern::containing('100 %'));
        self::assertSame('%Konto\\_2%', LikePattern::containing('Konto_2'));
        self::assertSame('%a\\\\b%', LikePattern::containing('a\\b'));
    }
}
