<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\UserInterface;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Kein alert(), confirm() oder prompt() in unseren Skripten.
 *
 * Die eingebauten Dialoge bringen das Aussehen des Browsers mit, nennen die
 * Adresse der Seite und halten den ganzen Reiter an, bis jemand klickt. Sie
 * sehen aus wie ein Fehler der Anwendung, und in ImmoBase sind sie einer:
 * fuer Hinweise gibt es notice() und components/notice_modal.html.twig, fuer
 * Rueckfragen components/confirm.html.twig.
 *
 * Der Fall, der diesen Test ausgeloest hat: der Eigentuemer-Picker meldete
 * einen doppelt gewaehlten Kontakt mit window.alert().
 */
final class NoNativeDialogTest extends TestCase
{
    /**
     * Nur der Aufruf, nicht das Wort — `confirmDelete(` ist keiner, `alert(`
     * und `window.alert(` sind welche. Kommentare sind vorher heraus.
     */
    private const string FORBIDDEN = '/\b(alert|confirm|prompt)\s*\(/';

    public function testNoScriptOpensABrowserDialog(): void
    {
        $offenders = [];

        foreach (self::scripts() as $path => $source) {
            foreach (self::withoutComments($source) as $line => $text) {
                if (1 === preg_match(self::FORBIDDEN, $text)) {
                    $offenders[] = $path.':'.$line;
                }
            }
        }

        self::assertSame([], $offenders, 'Diese Stellen oeffnen einen Browser-Dialog statt eines eigenen.');
    }

    /**
     * @return array<string, string>
     */
    private static function scripts(): array
    {
        $root = \dirname(__DIR__, 2).'/assets';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $scripts = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ('js' === $file->getExtension()) {
                $scripts[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $scripts;
    }

    /**
     * Zeilen ohne Kommentare, ab eins gezaehlt.
     *
     * @return array<int, string>
     */
    private static function withoutComments(string $source): array
    {
        $lines = [];

        foreach (explode("\n", $source) as $at => $text) {
            $lines[$at + 1] = (string) preg_replace('#(//.*|^\s*\*.*|/\*.*)#', '', $text);
        }

        return $lines;
    }
}
