<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Erzeugt assets/attributions.json fuer das Open-Source-Modal.
 *
 * Erzeugt statt gepflegt: eine handgeschriebene Liste ist nach dem dritten
 * composer require falsch, und dann fehlt eine Namensnennung, zu der wir
 * lizenzrechtlich verpflichtet sind.
 *
 * Aufruf: php tools/generate-attributions.php [--check]
 * Mit --check wird nur geprueft, ob die Datei aktuell ist.
 */

/**
 * Werke, die in keinem Lockfile stehen und deshalb fest eingetragen sind.
 *
 * Sie stehen ausserdem in NOTICE. Beide Listen zu fuehren ist unschoen, aber
 * NOTICE ist die Datei, in die ein Jurist schaut, und diese hier die, die
 * Nutzer im Programm sehen. Ein Test haelt fest, dass keine Quelle nur in
 * einer der beiden auftaucht.
 *
 * Mehrere Links je Eintrag, weil die Datenlizenz Deutschland genau das
 * verlangt: der Namensgeber und die Lizenz muessen einzeln verlinkt sein.
 *
 * @var list<array{name: string, license: string, note: string, modification?: string, links: list<array{label: string, url: string}>}>
 */
const MANUAL = [
    [
        'name' => 'BKG VG2500 (Verwaltungsgebiete 1:2 500 000)',
        'license' => 'dl-de/by-2-0',
        // Wortlaut und Verlinkung sind von der Lizenz vorgeschrieben und
        // duerfen nicht umformuliert werden.
        'note' => '© BKG (2026) dl-de/by-2-0, Datenquellen: https://sgx.geodatenzentrum.de/web_public/gdz/datenquellen/datenquellen_vg_nuts.pdf',
        'modification' => 'Umrisse nach EPSG:25832 umprojiziert, stark vereinfacht und nach SVG gewandelt. Für kartografische Zwecke nicht geeignet.',
        'links' => [
            ['label' => 'BKG', 'url' => 'https://www.bkg.bund.de'],
            ['label' => 'dl-de/by-2-0', 'url' => 'https://www.govdata.de/dl-de/by-2-0'],
        ],
    ],
    [
        'name' => 'Lucide',
        'license' => 'ISC',
        'note' => 'Symbole der Oberfläche.',
        'modification' => 'Breite, Höhe und Klassen entfernt, als schmückend ausgezeichnet. Pfade unverändert.',
        'links' => [['label' => 'lucide.dev', 'url' => 'https://lucide.dev']],
    ],
    [
        'name' => 'Simple Icons',
        'license' => 'CC0-1.0',
        'note' => 'GitHub-Symbol. Das GitHub-Logo ist eine Marke von GitHub, Inc. und steht hier ausschließlich als Kennzeichnung eines Links auf ein GitHub-Repository.',
        'links' => [['label' => 'simpleicons.org', 'url' => 'https://simpleicons.org']],
    ],
    [
        'name' => 'Harmony Contributor Agreements',
        'license' => 'CC-BY-3.0',
        'note' => 'Grundlage von CLA.md und CLA-CORPORATE.md.',
        'links' => [['label' => 'harmonyagreements.org', 'url' => 'https://www.harmonyagreements.org/']],
    ],
    [
        'name' => 'Contributor Covenant',
        'license' => 'CC-BY-4.0',
        'note' => 'Grundlage von CODE_OF_CONDUCT.md.',
        'links' => [['label' => 'contributor-covenant.org', 'url' => 'https://www.contributor-covenant.org/']],
    ],
];

/**
 * @return list<array{name: string, version: string, license: string, url: string}>
 */
function packagesFromLock(string $root): array
{
    $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);
    $packages = [];

    if (!is_array($lock) || !is_array($lock['packages'] ?? null)) {
        return $packages;
    }

    foreach ($lock['packages'] as $package) {
        if (!is_array($package) || !is_string($package['name'] ?? null)) {
            continue;
        }

        $licenses = array_values(array_filter(
            (array) ($package['license'] ?? []),
            static fn (mixed $license): bool => is_string($license),
        ));

        $packages[] = [
            'name' => $package['name'],
            'version' => is_string($package['version'] ?? null) ? $package['version'] : '',
            'license' => [] === $licenses ? 'unbekannt' : implode(' / ', $licenses),
            'url' => is_string($package['homepage'] ?? null) && '' !== $package['homepage']
                ? $package['homepage']
                : 'https://packagist.org/packages/'.$package['name'],
        ];
    }

    usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $packages;
}

$root = dirname(__DIR__);
$target = $root.'/assets/attributions.json';
// Nicht $argv: das ist nur bei aktivem register_argc_argv gesetzt.
$arguments = $_SERVER['argv'] ?? [];
$checkOnly = is_array($arguments) && in_array('--check', $arguments, true);

$packages = packagesFromLock($root);

// Bewusst ohne Zeitstempel: sonst änderte sich die Datei bei jedem Lauf und
// der Aktualitätsvergleich wäre wertlos.
$json = json_encode(
    ['generatedBy' => 'tools/generate-attributions.php', 'manual' => MANUAL, 'packages' => $packages],
    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
)."\n";

if (!$checkOnly) {
    file_put_contents($target, $json);

    printf("assets/attributions.json geschrieben: %d Pakete, %d feste Einträge.\n", count($packages), count(MANUAL));

    exit(0);
}

if (is_file($target) && file_get_contents($target) === $json) {
    printf("Attributionen: aktuell (%d Pakete, %d feste Einträge).\n", count($packages), count(MANUAL));

    exit(0);
}

echo "assets/attributions.json ist nicht aktuell.\n";
echo "Erzeugen mit: make attributions\n";

exit(1);
