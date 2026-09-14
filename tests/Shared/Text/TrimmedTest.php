<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Text;

use App\Shared\Text\Trimmed;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrimmedTest extends TestCase
{
    public function testKeepsTheTextWithoutSurroundingSpace(): void
    {
        self::assertSame('Erika Muster', Trimmed::required('  Erika Muster  ', 'Name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankValues(): iterable
    {
        yield 'leer' => [''];
        yield 'Leerzeichen' => ['   '];
        yield 'Tabulator' => ["\t"];
        yield 'Zeilenumbruch' => ["\n\n"];
        yield 'geschütztes Leerzeichen' => ["\u{00A0}"];
    }

    #[DataProvider('blankValues')]
    public function testRefusesWhatOnlyLooksLikeContent(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Name darf nicht leer sein');

        Trimmed::required($value, 'Name');
    }

    #[DataProvider('blankValues')]
    public function testTreatsTheSameValuesAsAbsent(string $value): void
    {
        self::assertNull(Trimmed::orNull($value));
    }

    public function testAbsentTextIsNull(): void
    {
        self::assertNull(Trimmed::orNull(null));
    }
}
