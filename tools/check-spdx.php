<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Prueft, ob jede Quelldatei die SPDX-Kopfzeilen aus
 * docs/licensing/header-policy.md traegt.
 *
 * Der SPDX-Wert ist die Grundlage dafuer, die Core/Plugin-Lizenzgrenze
 * spaeter maschinell pruefen zu koennen: Plugin-Dateien tragen dort einen
 * anderen Wert, und damit wird die Grenze im Dateisystem sichtbar.
 *
 * Aufruf: php tools/check-spdx.php
 */
const SCANNED_DIRECTORIES = ['src', 'tests', 'tools', 'migrations'];
const EXPECTED_LICENSE = 'SPDX-License-Identifier: AGPL-3.0-or-later';
const EXPECTED_COPYRIGHT = 'SPDX-FileCopyrightText:';

$root = dirname(__DIR__);
$missing = [];
$checked = 0;

foreach (SCANNED_DIRECTORIES as $relative) {
    $directory = $root.'/'.$relative;

    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
            continue;
        }

        ++$checked;
        $head = (string) file_get_contents($file->getPathname(), false, null, 0, 400);

        if (str_contains($head, EXPECTED_LICENSE) && str_contains($head, EXPECTED_COPYRIGHT)) {
            continue;
        }

        $missing[] = substr($file->getPathname(), strlen($root) + 1);
    }
}

if ([] === $missing) {
    printf("SPDX: %d Dateien geprüft, alle tragen die Kopfzeilen.\n", $checked);

    exit(0);
}

sort($missing);

printf("SPDX-Kopfzeilen fehlen in %d Datei(en):\n\n", count($missing));

foreach ($missing as $path) {
    echo '  '.$path."\n";
}

echo "\nErwartet werden diese beiden Zeilen im Dateikopf:\n";
echo '  // '.EXPECTED_LICENSE."\n";
echo '  // '.EXPECTED_COPYRIGHT." 2026 s3gv\n";
echo "\nSiehe docs/licensing/header-policy.md.\n";

exit(1);
