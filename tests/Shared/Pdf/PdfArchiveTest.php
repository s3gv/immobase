<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Pdf;

use App\Shared\Pdf\PdfArchive;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Zweimal gepackt heisst byteweise gleich.
 *
 * ZIP schreibt je Eintrag eine Uhrzeit, und ohne Zutun ist es die aktuelle.
 * Zwei Laeufe desselben Vorgangs ergaeben damit zwei verschiedene Archive —
 * aber nur dann, wenn sie zufaellig ueber eine Sekundengrenze fallen. Ein
 * Test, der beide Laeufe hintereinander macht, faende das fast nie; er sagt
 * dann jahrelang „gruen" und schlaegt irgendwann ohne erkennbaren Grund fehl.
 *
 * Darum wird hier die Uhr bewegt und nicht gehofft.
 */
final class PdfArchiveTest extends TestCase
{
    public function testTheSameLettersGiveTheSameBytes(): void
    {
        $letters = ['HG-1.pdf' => 'ein Brief', 'HG-2.pdf' => 'noch einer'];
        $day = new DateTimeImmutable('2027-03-01');

        self::assertSame(PdfArchive::of($letters, $day), PdfArchive::of($letters, $day));
    }

    /**
     * Auch wenn zwischen den beiden Laeufen Zeit vergeht.
     *
     * Genau daran ist die Zusicherung vorher gescheitert: `addFromString()`
     * nahm die Uhrzeit des Packens, und die war beim zweiten Lauf eine andere.
     */
    public function testTimePassingBetweenTwoRunsChangesNothing(): void
    {
        $letters = ['WP-1.pdf' => 'derselbe Inhalt'];
        $day = new DateTimeImmutable('2027-03-01');

        $first = PdfArchive::of($letters, $day);
        usleep(2_100_000);

        self::assertSame($first, PdfArchive::of($letters, $day), 'Zwei Sekunden später dieselbe Datei');
    }

    /** Ein anderer Vorgangstag ergibt ein anderes Archiv — der Tag steht darin. */
    public function testTheDayOfTheCaseIsPartOfIt(): void
    {
        $letters = ['HG-1.pdf' => 'ein Brief'];

        self::assertNotSame(
            PdfArchive::of($letters, new DateTimeImmutable('2027-03-01')),
            PdfArchive::of($letters, new DateTimeImmutable('2027-03-08')),
        );
    }
}
