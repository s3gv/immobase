<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Number;

use App\Shared\Number\WholeNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Was keine ganze Zahl ist, wird keine.
 *
 * Der Fall, der diesen Test ausgeloest hat: die Formularfelder gingen per
 * (int)-Cast in die Domaene. Der macht aus „abc" eine 0, aus „7 Stueck" eine
 * 7 und aus einer zwanzigstelligen Zahl PHP_INT_MAX — lauter Angaben, die
 * niemand gemacht hat und die hinterher wie Angaben aussehen.
 */
final class WholeNumberTest extends TestCase
{
    public function testReadsAWholeNumber(): void
    {
        self::assertSame(1974, WholeNumber::orNull('1974'));
        self::assertSame(-3, WholeNumber::orNull('-3'));
        self::assertSame(0, WholeNumber::orNull(' 0 '));
    }

    /** Ein leeres Feld ist keine Null, sondern keine Angabe. */
    public function testAnEmptyFieldIsNoStatement(): void
    {
        self::assertNull(WholeNumber::orNull(''));
        self::assertNull(WholeNumber::orNull('   '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'Buchstaben' => ['abc'];
        yield 'Zahl mit Anhang' => ['7 Stück'];
        yield 'Kommazahl' => ['3,5'];
        yield 'Vorzeichen allein' => ['-'];
        yield 'zu viele Stellen' => ['99999999999999999999'];
        yield 'Hexadezimal' => ['0x1A'];
        yield 'Exponent' => ['1e3'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsNotAWholeNumber(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        WholeNumber::orNull($input);
    }
}
