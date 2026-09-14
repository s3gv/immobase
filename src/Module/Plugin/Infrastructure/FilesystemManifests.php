<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\ManifestSource;

/**
 * Was unter `plugins/` liegt.
 *
 * Nur gelesen, nie ausgefuehrt: ein Manifest ist eine Datei mit Angaben, kein
 * Code, den der Core laedt. Das ist der Grund, warum die Grenze traegt — ein
 * Plugin, dessen Klassen hier eingebunden wuerden, waere mit dem Core ein
 * gemeinsames Werk.
 */
final readonly class FilesystemManifests implements ManifestSource
{
    public function __construct(private string $directory)
    {
    }

    public function all(): array
    {
        $found = [];
        $files = glob($this->directory.'/*/manifest.json');

        foreach (false === $files ? [] : $files as $file) {
            $name = basename(\dirname($file));
            $json = file_get_contents($file);

            if (\is_string($json)) {
                $found[$name] = $json;
            }
        }

        ksort($found);

        return $found;
    }
}
