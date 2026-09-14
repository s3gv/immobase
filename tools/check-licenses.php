<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Prueft die Laufzeit-Abhaengigkeiten gegen docs/licensing/dependency-policy.md.
 *
 * Geprueft wird nur der Abschnitt "packages" aus composer.lock. Der Abschnitt
 * "packages-dev" ist ausgenommen, weil Entwicklungswerkzeuge nicht ausgeliefert
 * werden und damit nicht Teil des verteilten Werks sind.
 *
 * Ein Paket gilt als zulaessig, wenn mindestens eine seiner Lizenzen permissiv
 * ist — mehrfach lizenzierte Pakete duerfen unter der permissiven Option
 * verwendet werden.
 *
 * Es gibt bewusst keinen Ausnahmemechanismus im Code. Sollte je eine Ausnahme
 * noetig werden, wird sie mit Begruendung in der Policy festgehalten und die
 * Erlaubnisliste hier entsprechend erweitert — eine leere Ausnahmeliste, die
 * niemand nutzt, waere nur eine Einladung, sie zu fuellen.
 *
 * Aufruf: php tools/check-licenses.php
 */
const ALLOWED_LICENSES = [
    'MIT',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'Apache-2.0',
    'ISC',
    'Unlicense',
    'CC0-1.0',
];

$root = dirname(__DIR__);
$lockFile = $root.'/composer.lock';

if (!is_file($lockFile)) {
    echo "composer.lock nicht gefunden. Erst 'composer install' ausführen.\n";

    exit(1);
}

$lock = json_decode((string) file_get_contents($lockFile), true, 512, \JSON_THROW_ON_ERROR);

if (!is_array($lock) || !is_array($lock['packages'] ?? null)) {
    echo "composer.lock hat kein lesbares 'packages'-Feld.\n";

    exit(1);
}

$rejected = [];

foreach ($lock['packages'] as $package) {
    if (!is_array($package) || !is_string($package['name'] ?? null)) {
        continue;
    }

    $name = $package['name'];
    $licenses = [];

    foreach ((array) ($package['license'] ?? []) as $license) {
        if (is_string($license)) {
            $licenses[] = $license;
        }
    }

    if ([] === $licenses) {
        $rejected[$name] = 'keine Lizenz angegeben';

        continue;
    }

    if ([] === array_intersect($licenses, ALLOWED_LICENSES)) {
        $rejected[$name] = implode(' / ', $licenses);
    }
}

/**
 * Die eigene Lizenzangabe muss zur LICENSE-Datei passen.
 *
 * Das Symfony-Skelett liefert "proprietary" mit. Bleibt das stehen, sagt die
 * Paketangabe das Gegenteil von LICENSE, NOTICE und jeder SPDX-Kopfzeile —
 * und Werkzeuge, die Lizenzen maschinell auswerten, glauben der
 * Paketangabe.
 */
const OWN_LICENSE = 'AGPL-3.0-or-later';

$manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
$ownLicense = is_array($manifest) && is_string($manifest['license'] ?? null) ? $manifest['license'] : '';

if (OWN_LICENSE !== $ownLicense) {
    printf("composer.json gibt \"%s\" als Lizenz an, erwartet ist \"%s\".\n", $ownLicense, OWN_LICENSE);

    exit(1);
}

if ([] === $rejected) {
    printf(
        "Lizenzen: eigene Angabe %s, alle %d Laufzeit-Abhängigkeiten permissiv.\n",
        OWN_LICENSE,
        count($lock['packages']),
    );

    exit(0);
}

printf("Nicht zugelassene Lizenzen in %d Laufzeit-Abhängigkeit(en):\n\n", count($rejected));

foreach ($rejected as $name => $license) {
    printf('  %s %s'."\n", str_pad($name, 42), $license);
}

echo "\nZugelassen sind: ".implode(', ', ALLOWED_LICENSES)."\n";
echo "Copyleft-Abhängigkeiten dürfen nur hinter der Prozessgrenze laufen.\n";
echo "Siehe docs/licensing/dependency-policy.md.\n";

exit(1);
