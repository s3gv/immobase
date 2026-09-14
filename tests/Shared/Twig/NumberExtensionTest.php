<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Twig;

use App\Shared\Locale\CurrentLocale;
use App\Shared\Twig\NumberExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Basispunkte als Prozentsatz.
 *
 * Der Fall, an dem es haengt: **ein negativer Satz unter einem Prozent.**
 * Ganzzahlig geteilt sind −88 Basispunkte null, und wer das Vorzeichen aus
 * der Division nimmt, druckt „0,88 %", wo „−0,88 %" gilt. Genau dort lag der
 * Basiszinssatz nach § 247 BGB von Mitte 2016 bis Ende 2022 — jede
 * Verzugszinsrechnung aus diesen Jahren haette ein falsches Vorzeichen auf
 * dem Blatt.
 */
final class NumberExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function rates(): iterable
    {
        yield 'voller Satz' => [1900, '19,00 %'];
        yield 'mit Nachkommastellen' => [152, '1,52 %'];
        yield 'unter einem Prozent' => [52, '0,52 %'];
        yield 'negativ unter einem Prozent' => [-88, '-0,88 %'];
        yield 'negativ, genau ein Prozent' => [-100, '-1,00 %'];
        yield 'negativ darueber' => [-412, '-4,12 %'];
        yield 'null' => [0, '0,00 %'];
    }

    #[DataProvider('rates')]
    public function testBasisPointsBecomeAPercentage(int $basisPoints, string $expected): void
    {
        self::assertSame($expected, self::extension()->percent($basisPoints));
    }

    /** Ohne Zeichen — fuer Eingabefelder, deren Inhalt wieder eingelesen wird. */
    public function testTheSignCanBeLeftOff(): void
    {
        self::assertSame('-0,88', self::extension()->percent(-88, false));
    }

    private static function extension(): NumberExtension
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $request->setLocale('de');
        $requests->push($request);

        return new NumberExtension(new CurrentLocale($requests, 'de'));
    }
}
