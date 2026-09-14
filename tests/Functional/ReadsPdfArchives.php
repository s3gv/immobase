<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use ZipArchive;

/**
 * Ein Archiv voller Briefe, lesbar gemacht.
 *
 * FPDF schreibt die Seiten als zlib-Stroeme und die Zeichenketten in CP1252;
 * beides wird hier zurueckgedreht, damit ein Test das liest, was der
 * Empfaenger liest — und nicht Bytes.
 *
 * An einer Stelle fuer alle Schreiben dieses Moduls: Abrechnung und
 * Wirtschaftsplan kommen aus derselben Maschinerie, und zwei Leser dafuer
 * liefen frueher oder spaeter auseinander.
 */
trait ReadsPdfArchives
{
    /**
     * Der sichtbare Text aller PDFs im Archiv.
     *
     * FPDF schreibt die Seiten als zlib-Stroeme und die Zeichenketten in
     * CP1252; beides wird hier zurueckgedreht, damit der Test das liest, was
     * der Empfaenger liest — und nicht Bytes.
     */
    private static function textIn(string $bundle): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        self::assertIsString($path);
        file_put_contents($path, $bundle);

        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));

        $text = '';

        for ($at = 0; $at < $archive->numFiles; ++$at) {
            $text .= self::readable((string) $archive->getFromIndex($at));
        }

        $archive->close();
        unlink($path);

        return $text;
    }

    /**
     * Die Seiten je Brief im Archiv.
     *
     * Fuer die Frage, ob ein langer Brief sauber umbricht: eine Seite mehr
     * als noetig ist eine, auf der fast nichts steht.
     *
     * @return list<int>
     */
    private static function pagesIn(string $bundle): array
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        self::assertIsString($path);
        file_put_contents($path, $bundle);

        $archive = new ZipArchive();
        self::assertTrue($archive->open($path));

        $pages = [];

        for ($at = 0; $at < $archive->numFiles; ++$at) {
            $pdf = (string) $archive->getFromIndex($at);

            // `/Type /Pages` ist der Seitenbaum und keine Seite — ohne den
            // Abzug haette jeder Brief eine Seite zu viel.
            $pages[] = substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
        }

        $archive->close();
        unlink($path);

        return $pages;
    }

    /** Die Zeichenketten eines einzelnen PDF. */
    private static function readable(string $pdf): string
    {
        $found = preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);
        self::assertNotFalse($found);

        $text = '';

        foreach ($streams[1] as $stream) {
            $page = @gzuncompress($stream);

            if (false === $page) {
                continue;
            }

            $shown = [];

            if (0 < (int) preg_match_all('/\((.*?)\)\s*Tj/', $page, $shown)) {
                $text .= implode(' ', $shown[1]);
            }
        }

        return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /**
     * Wie viele Dateien im Archiv liegen.
     *
     * Ueber eine Datei und nicht ueber die Bytes: ZipArchive kann nur mit
     * einer, und der Test soll das Archiv so lesen, wie ein Mensch es oeffnet.
     */
    private static function filesIn(string $bundle): int
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        self::assertIsString($path);
        file_put_contents($path, $bundle);

        $archive = new ZipArchive();
        $archive->open($path);
        $count = $archive->numFiles;
        $archive->close();
        unlink($path);

        return $count;
    }
}
