<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Pdf;

use App\Shared\Pdf\PostalLines;
use PHPUnit\Framework\TestCase;

/**
 * Das Anschriftfeld: hoechstens sechs Zeilen, keine davon leer.
 *
 * Die Zahl steht in der Norm, und sie ist keine Zierde: ein Fensterkuvert
 * zeigt genau dieses Feld. Was darunter steht, sieht niemand — und wer eine
 * Anschrift sieben Zeilen lang macht, verliert die Postleitzahl.
 */
final class PostalLinesTest extends TestCase
{
    public function testTheNameComesFirstAndTheRestFollows(): void
    {
        $lines = PostalLines::of('Tobias Wagner', ['c/o Hausverwaltung Nord', 'Lindenallee 8', '40233 Düsseldorf']);

        self::assertSame(
            ['Tobias Wagner', 'c/o Hausverwaltung Nord', 'Lindenallee 8', '40233 Düsseldorf'],
            $lines->lines,
        );
    }

    /** Eine leere Zusatzzeile ist keine Zeile — sie stuende als Luecke da. */
    public function testEmptyLinesDoNotTakeUpRoom(): void
    {
        $lines = PostalLines::of('Paula Prüfer', ['', '   ', 'Kontrollweg 9', '40233 Düsseldorf']);

        self::assertSame(['Paula Prüfer', 'Kontrollweg 9', '40233 Düsseldorf'], $lines->lines);
    }

    public function testTheSeventhLineFallsOutOfTheWindow(): void
    {
        $lines = PostalLines::of('Eins', ['Zwei', 'Drei', 'Vier', 'Fünf', 'Sechs', 'Sieben']);

        self::assertCount(PostalLines::MOST, $lines->lines);
        self::assertNotContains('Sieben', $lines->lines);
    }

    /**
     * Ein eingefrorener Block wird wieder zu Zeilen — auch der alte.
     *
     * Schreiben, die vor der Umstellung herausgingen, tragen die Anschrift
     * zweizeilig. Sie bleibt, wie sie ist: was eingefroren wurde, aendert
     * sich nicht, auch nicht zum Besseren.
     */
    public function testAFrozenBlockComesBackAsItWas(): void
    {
        $lines = PostalLines::fromText('Lindenallee 8, 40233 Düsseldorf');

        self::assertSame(['Lindenallee 8, 40233 Düsseldorf'], $lines->lines);
    }

    /** Im Text steht dieselbe Anschrift in einer Zeile. */
    public function testInlineForEverythingThatIsNotTheAddressField(): void
    {
        $lines = PostalLines::fromText("c/o Hausverwaltung Nord\nLindenallee 8\n40233 Düsseldorf");

        self::assertSame('c/o Hausverwaltung Nord, Lindenallee 8, 40233 Düsseldorf', $lines->inline());
    }
}
