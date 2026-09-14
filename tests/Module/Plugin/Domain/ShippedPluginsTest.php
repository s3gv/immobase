<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Domain;

use App\Module\Plugin\Domain\Manifest\ManifestReader;
use App\Module\Plugin\Domain\Manifest\TableReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Die Plugins, die mitgeliefert werden.
 *
 * Zwei Zusicherungen, und die zweite ist die Lizenz selbst.
 */
final class ShippedPluginsTest extends TestCase
{
    /**
     * Kein Plugin des Projekts laedt Core-Code.
     *
     * **Das ist keine Stilfrage.** Der Core steht unter AGPLv3; ein Plugin,
     * das seine Klassen laedt, waere mit ihm ein gemeinsames Werk und
     * muesste selbst AGPLv3 sein. Genau darauf beruht die Zusage, dass ein
     * Dritter ein kommerzielles Plugin gegen diesen Core bauen darf — und
     * eine Zusage, die nur in einer Datei steht, ist keine.
     */
    public function testNoPluginReachesIntoTheCore(): void
    {
        $guilty = [];

        foreach (self::loadableFilesUnderPlugins() as $file) {
            $content = file_get_contents($file->getPathname());

            if (\is_string($content) && str_contains($content, 'App\\')) {
                $guilty[] = $file->getPathname();
            }
        }

        self::assertSame([], $guilty, 'Ein Plugin nennt den Namensraum des Cores');
    }

    /** Was mitgeliefert wird, laesst sich auch aktivieren. */
    public function testEveryShippedManifestReads(): void
    {
        $reader = new ManifestReader(new TableReader());
        $seen = 0;

        $files = glob(self::pluginsDirectory().'/*/manifest.json');

        foreach (false === $files ? [] : $files as $file) {
            $json = file_get_contents($file);
            self::assertIsString($json, $file);

            $manifest = $reader->read(basename(\dirname($file)), $json);

            self::assertNotSame('', $manifest->label('de'), $file);
            ++$seen;
        }

        self::assertGreaterThan(0, $seen, 'Es liegt ein Plugin als Vorlage dabei');
    }

    /**
     * Alles, was Code sein oder Code laden kann.
     *
     * PHP-Dateien, und dazu jede `composer.json` — dort laege das eigentliche
     * Schlupfloch: ein Autoload-Eintrag auf `../../src` braeuchte keine
     * einzige `use`-Zeile, um den Core mitzuziehen. Prosa bleibt draussen;
     * die README erklaert die Regel und nennt sie dabei beim Namen.
     *
     * `vendor/` gehoert dem Plugin und wird nicht eingecheckt — was dort
     * liegt, ist nicht seine Aussage ueber sich selbst.
     *
     * @return iterable<SplFileInfo>
     */
    private static function loadableFilesUnderPlugins(): iterable
    {
        $directory = new RecursiveDirectoryIterator(self::pluginsDirectory(), RecursiveDirectoryIterator::SKIP_DOTS);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            $loadable = 'php' === $file->getExtension() || 'composer.json' === $file->getFilename();

            if ($file->isFile() && $loadable && !str_contains($file->getPathname(), '/vendor/')) {
                yield $file;
            }
        }
    }

    private static function pluginsDirectory(): string
    {
        return \dirname(__DIR__, 4).'/plugins';
    }
}
