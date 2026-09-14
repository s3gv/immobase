<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Pdf;

use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Der Umbruch eines Briefbogens.
 *
 * FPDF bricht von selbst um, sobald eine Zelle unter den Rand geraet — und
 * zwar mitten im Block: die Beschriftung steht dann auf der einen Seite und
 * ihr Betrag auf der naechsten. Wer vorher nach Platz fragt, bekommt entweder
 * dieselbe Hoehe oder den oberen Rand eines neuen Blattes.
 */
final class SheetTest extends TestCase
{
    /** A4 ist 297 mm hoch, unten bleiben 25 — bis 272 ist Platz. */
    public function testABlockThatFitsStaysWhereItIs(): void
    {
        $sheet = self::aSheet();

        self::assertSame(100.0, $sheet->room(100.0, 20.0));
        self::assertSame(1, $sheet->PageNo(), 'Kein Blatt zu viel');
    }

    public function testABlockThatDoesNotFitStartsANewPage(): void
    {
        $sheet = self::aSheet();

        self::assertSame(32.0, $sheet->room(260.0, 20.0), 'Oben auf dem neuen Blatt');
        self::assertSame(2, $sheet->PageNo());
    }

    /** Genau bis an den Rand ist noch Platz — ein Millimeter darueber nicht. */
    public function testTheEdgeCounts(): void
    {
        $sheet = self::aSheet();

        self::assertSame(252.0, $sheet->room(252.0, 20.0), '272 ist der letzte Millimeter');
        self::assertSame(32.0, $sheet->room(253.0, 20.0));
    }

    private static function aSheet(): Sheet
    {
        $sheet = new Sheet(new DateTimeImmutable('2026-01-01'));
        $sheet->AddPage();
        $sheet->continueWith('HG-20001/1-2026-1-1');

        return $sheet;
    }
}
