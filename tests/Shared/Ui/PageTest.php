<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Ui;

use App\Shared\Ui\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testAnEmptyListStillHasOnePage(): void
    {
        $page = Page::of(1, 0);

        self::assertSame(1, $page->number);
        self::assertSame(1, $page->pages);
        self::assertSame(0, $page->offset());
        self::assertTrue($page->isEmpty());
    }

    public function testAFullPageIsStillOnePage(): void
    {
        self::assertSame(1, Page::of(1, Page::PER_PAGE)->pages);
    }

    public function testOneMoreThanAPageIsTwo(): void
    {
        self::assertSame(2, Page::of(1, Page::PER_PAGE + 1)->pages);
    }

    public function testTheSecondPageSkipsTheFirst(): void
    {
        self::assertSame(Page::PER_PAGE, Page::of(2, 120)->offset());
    }

    /**
     * Die Seitenzahl kommt aus der Adresszeile. Sie wird zurechtgerueckt,
     * nicht abgelehnt — eine Uebersicht, die auf "?page=0" mit einem Fehler
     * antwortet, ist niemandem eine Hilfe.
     *
     * @return iterable<string, array{int, int, int}>
     */
    public static function unusableNumbers(): iterable
    {
        yield 'null' => [0, 120, 1];
        yield 'negativ' => [-7, 120, 1];
        yield 'hinter der letzten Seite' => [99, 120, 3];
        yield 'weit hinter der letzten Seite' => [\PHP_INT_MAX, 120, 3];
        yield 'auf einer leeren Liste' => [5, 0, 1];
    }

    #[DataProvider('unusableNumbers')]
    public function testMovesAnImpossiblePageToTheNearestReal(int $requested, int $total, int $expected): void
    {
        self::assertSame($expected, Page::of($requested, $total)->number);
    }

    public function testANegativeTotalCountsAsEmpty(): void
    {
        self::assertSame(0, Page::of(1, -3)->total);
    }

    /**
     * Der Versatz muss immer auf einer echten Zeile landen.
     */
    public function testTheOffsetNeverPassesTheEnd(): void
    {
        foreach ([0, 1, 49, 50, 51, 100, 101, 1000] as $total) {
            $page = Page::of(\PHP_INT_MAX, $total);

            self::assertLessThanOrEqual(
                max(0, $total - 1),
                $page->offset(),
                \sprintf('Bei %d Einträgen zeigt die letzte Seite ins Leere.', $total),
            );
        }
    }
}
