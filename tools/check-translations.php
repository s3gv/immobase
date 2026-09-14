<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Prueft, ob alle Uebersetzungsschluessel in jeder Sprache vorhanden sind.
 *
 * Fehlende Schluessel sind in Symfony kein Fehler: der Uebersetzer faellt
 * stillschweigend auf die Ausgangssprache zurueck oder gibt den Schluessel
 * selbst aus. Beides faellt erst auf, wenn ein Nutzer "nav.properties" auf dem
 * Bildschirm liest.
 *
 * Aufruf: php tools/check-translations.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

const LOCALES = ['de', 'en'];

/**
 * Flacht verschachtelte Uebersetzungen zu Punktschluesseln ab.
 *
 * @param array<mixed, mixed> $values
 *
 * @return list<string>
 */
function flattenKeys(array $values, string $prefix = ''): array
{
    $keys = [];

    foreach ($values as $key => $value) {
        $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys($value, $path));

            continue;
        }

        $keys[] = $path;
    }

    return $keys;
}

/**
 * @return list<string>
 */
function keysOf(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $parsed = Yaml::parseFile($file);

    return is_array($parsed) ? flattenKeys($parsed) : [];
}

$root = dirname(__DIR__);
$directory = $root.'/translations';

if (!is_dir($directory)) {
    echo "Übersetzungen: kein translations/-Verzeichnis, nichts zu prüfen.\n";

    exit(0);
}

$domains = [];

$translationFiles = glob($directory.'/*.yaml');

foreach (false === $translationFiles ? [] : $translationFiles as $file) {
    $name = basename($file, '.yaml');

    if (1 === preg_match('/^(?<domain>.+)\.(?<locale>[a-z]{2})$/', $name, $matches)) {
        $domains[$matches['domain']][$matches['locale']] = $file;
    }
}

if ([] === $domains) {
    echo "Übersetzungen: keine Dateien gefunden, nichts zu prüfen.\n";

    exit(0);
}

$problems = [];
$checked = 0;

foreach ($domains as $domain => $files) {
    $keysByLocale = [];

    foreach (LOCALES as $locale) {
        if (!isset($files[$locale])) {
            $problems[] = sprintf('Domäne "%s" fehlt ganz für Sprache "%s".', $domain, $locale);

            continue;
        }

        $keysByLocale[$locale] = keysOf($files[$locale]);
    }

    if (count($keysByLocale) < count(LOCALES)) {
        continue;
    }

    $all = array_unique(array_merge(...array_values($keysByLocale)));
    sort($all);
    $checked += count($all);

    foreach ($keysByLocale as $locale => $keys) {
        foreach (array_diff($all, $keys) as $missing) {
            $problems[] = sprintf('%s.%s.yaml fehlt: %s', $domain, $locale, $missing);
        }
    }
}

if ([] === $problems) {
    printf("Übersetzungen: %d Schlüssel in %d Sprache(n) vollständig.\n", $checked, count(LOCALES));

    exit(0);
}

sort($problems);

printf("%d Lücke(n) in den Übersetzungen:\n\n", count($problems));

foreach ($problems as $problem) {
    echo '  '.$problem."\n";
}

echo "\nJeder Schlüssel muss in allen Sprachen stehen. Deutsch ist Standard,\n";
echo "Englisch wird vollständig gepflegt — siehe Foundation-Spec 7.8.\n";

exit(1);
