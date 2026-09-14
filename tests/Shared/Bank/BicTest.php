<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Bank;

use App\Shared\Bank\Bic;
use App\Shared\Bank\NotABic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die BIC hat keine Pruefziffer — gepruefft wird ihre Gestalt.
 */
final class BicTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'acht Stellen' => ['COBADEFF', 'COBADEFF'];
        yield 'elf Stellen mit Filiale' => ['COBADEFFXXX', 'COBADEFFXXX'];
        yield 'klein geschrieben' => ['cobadeff', 'COBADEFF'];
        yield 'mit Leerzeichen' => ['COBA DE FF', 'COBADEFF'];
    }

    #[DataProvider('accepted')]
    public function testNormalisesAndAccepts(string $input, string $expected): void
    {
        self::assertSame($expected, Bic::fromString($input)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'zu kurz' => ['COBADE'];
        yield 'neun Stellen' => ['COBADEFFX'];
        yield 'Ziffern im Bankteil' => ['C0BADEFF'];
        yield 'Ziffern im Länderteil' => ['COBAD1FF'];
        yield 'leer' => [''];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsNotABic(string $input): void
    {
        $this->expectException(NotABic::class);

        Bic::fromString($input);
    }

    public function testAnEmptyValueIsNothingAtAll(): void
    {
        self::assertNull(Bic::orNull('  '));
        self::assertNotNull(Bic::orNull('COBADEFF'));
    }
}
