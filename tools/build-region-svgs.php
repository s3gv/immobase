<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Leitet aus BKG-VG2500-GeoJSON je Bundesland einen stark vereinfachten
 * SVG-Umriss ab.
 *
 * Einmalig auszufuehren; die Ergebnisse liegen im Repository. Weder GDAL noch
 * die BKG-Daten sind Laufzeitabhaengigkeiten.
 *
 * Vorbereitung:
 *   VG2500-Shape-Paket von https://gdz.bkg.bund.de herunterladen, entpacken und
 *   ogr2ogr -f GeoJSON -t_srs EPSG:25832 laender.geojson vg2500/VG2500_LAN.shp
 *
 * WICHTIG: EPSG:25832 (UTM32), NICHT EPSG:4326. Laengen- und Breitengrade
 * direkt als X/Y zu zeichnen staucht die Umrisse vertikal — auf Deutschlands
 * Breite ist ein Laengengrad nur etwa 0,63 Breitengrade breit. UTM32 ist ein
 * metrisches Projektionssystem und laesst sich unmittelbar zeichnen.
 *
 * Aufruf:
 *   php tools/build-region-svgs.php /pfad/zu/laender.geojson
 *
 * Die Vereinfachung ist bewusst grob: der Umriss wird spaeter geblurrt und so
 * stark gezoomt dargestellt, dass nur etwa ein Viertel sichtbar ist.
 * Geografische Genauigkeit hat hier keinen Wert.
 */

/**
 * Rastergroesse der Vereinfachung, in SVG-Einheiten.
 *
 * Punkte werden auf dieses Raster gerundet und aufeinanderfolgende Dubletten
 * entfernt. Entscheidend ist, dass gerastert und nicht nach Index ausgeduennt
 * wird: benachbarte Bundeslaender fuehren ihre gemeinsame Grenze als zwei
 * getrennte Ringe. Duennt man jeden unabhaengig nach Index aus, behalten die
 * beiden unterschiedliche Punkte und die Linien klaffen auseinander. Auf einem
 * gemeinsamen Raster rasten identische Quellpunkte identisch ein.
 */
const GRID = 2.0;

/** Ringe mit weniger Punkten sind Inseln und Exklaven ohne Belang fuer die Silhouette. */
const MIN_POINTS_PER_RING = 12;

const VIEWBOX = 1000;

/** Geometriefaktor 9 sind die Landflaechen der Bundeslaender; 8 enthaelt Wasser. */
const LAND_GEOMETRY_FACTOR = 9;

/**
 * @param array<mixed> $geometry
 *
 * @return list<list<array{float, float}>>
 */
function outerRings(array $geometry): array
{
    $coordinates = $geometry['coordinates'] ?? null;

    if (!is_array($coordinates)) {
        return [];
    }

    $polygons = 'Polygon' === ($geometry['type'] ?? null) ? [$coordinates] : $coordinates;
    $rings = [];

    foreach ($polygons as $polygon) {
        if (!is_array($polygon) || !is_array($polygon[0] ?? null)) {
            continue;
        }

        $ring = [];

        foreach ($polygon[0] as $point) {
            if (is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $ring[] = [(float) $point[0], (float) $point[1]];
            }
        }

        if (count($ring) >= MIN_POINTS_PER_RING) {
            $rings[] = $ring;
        }
    }

    return $rings;
}

/**
 * @param list<list<array{float, float}>> $rings
 *
 * @return array{float, float, float}
 */
function boundsOf(array $rings): array
{
    $minX = $minY = \PHP_FLOAT_MAX;
    $maxX = $maxY = -\PHP_FLOAT_MAX;

    foreach ($rings as $ring) {
        foreach ($ring as [$x, $y]) {
            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }
    }

    return [$minX, $maxY, max($maxX - $minX, $maxY - $minY)];
}

/**
 * @param list<list<array{float, float}>> $rings
 *
 * @return list<string>
 */
function pathsFor(array $rings, float $minX, float $maxY, float $span, float $grid = GRID): array
{
    $paths = [];

    foreach ($rings as $ring) {
        $points = [];
        $previous = null;

        foreach ($ring as [$x, $y]) {
            // Y spiegeln: in Geodaten waechst der Wert nach Norden, im SVG nach unten.
            $sx = round(($x - $minX) / $span * VIEWBOX / $grid) * $grid;
            $sy = round(($maxY - $y) / $span * VIEWBOX / $grid) * $grid;

            $point = sprintf('%.1f %.1f', $sx, $sy);

            if ($point !== $previous) {
                $points[] = $point;
                $previous = $point;
            }
        }

        if (count($points) > 3) {
            $paths[] = 'M'.implode('L', $points).'Z';
        }
    }

    return $paths;
}

function slugify(string $name): string
{
    $slug = strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
}

$arguments = $_SERVER['argv'] ?? [];
$source = is_array($arguments) ? ($arguments[1] ?? null) : null;

if (!is_string($source) || !is_file($source)) {
    fwrite(\STDERR, "Aufruf: php tools/build-region-svgs.php <pfad/zu/laender.geojson>\n");

    exit(1);
}

$outputDirectory = dirname(__DIR__).'/assets/images/regions';

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0o755, true) && !is_dir($outputDirectory)) {
    fwrite(\STDERR, "Verzeichnis $outputDirectory liess sich nicht anlegen.\n");

    exit(1);
}

$geojson = json_decode((string) file_get_contents($source), true, 512, \JSON_THROW_ON_ERROR);

if (!is_array($geojson) || !is_array($geojson['features'] ?? null)) {
    fwrite(\STDERR, "Die Datei enthaelt keine GeoJSON-Features.\n");

    exit(1);
}

/**
 * Alle Landflaechen, eingesammelt fuer die Gesamtkarte.
 *
 * @var array<string, list<list<array{float, float}>>>
 */
$byRegion = [];

foreach ($geojson['features'] as $feature) {
    if (!is_array($feature) || !is_array($feature['geometry'] ?? null)) {
        continue;
    }

    $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

    if (LAND_GEOMETRY_FACTOR !== ($properties['GF'] ?? null)) {
        continue;
    }

    $name = is_string($properties['GEN'] ?? null) ? $properties['GEN'] : '';
    $rings = outerRings($feature['geometry']);

    if ('' === $name || [] === $rings) {
        continue;
    }

    $byRegion[$name] = $rings;
}

/**
 * Schreibt eine SVG-Datei aus fertigen Pfaden.
 *
 * @param list<string> $paths
 */
function writeSvg(string $directory, string $name, array $paths): int
{
    if ([] === $paths) {
        return 0;
    }

    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" fill="none" aria-hidden="true">'
        // vector-effect haelt die Linie unabhaengig vom Zoom gleich duenn. Ohne
        // das wird aus der Linie bei starker Vergroesserung ein fettes Band.
        .'<path d="%s" stroke="currentColor" stroke-width="2" stroke-linejoin="round"'
        .' stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
        ."</svg>\n",
        VIEWBOX,
        VIEWBOX,
        implode(' ', $paths),
    );

    $target = $directory.'/'.slugify($name).'.svg';
    file_put_contents($target, $svg);

    printf("%-24s %6d Bytes\n", $name, strlen($svg));

    return 1;
}

$written = 0;

foreach ($byRegion as $name => $rings) {
    [$minX, $maxY, $span] = boundsOf($rings);

    if ($span > 0.0) {
        $written += writeSvg($outputDirectory, $name, pathsFor($rings, $minX, $maxY, $span));
    }
}

printf("\n%d Umrisse geschrieben nach %s\n", $written, $outputDirectory);
echo "Quelle: BKG VG2500, dl-de/by-2-0, bearbeitet.\n";
