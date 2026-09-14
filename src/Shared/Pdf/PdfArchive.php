<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Ein Archiv voller Schreiben — zweimal erzeugt, zweimal dieselben Bytes.
 *
 * Wer abrechnet, verschickt ein Objekt und nicht eine Wohnung: das Archiv ist
 * die Form, in der ein Lauf herausgeht.
 *
 * **Der Zeitstempel gehoert zum Vorgang und nicht zur Uhr.** ZIP schreibt je
 * Eintrag eine Uhrzeit, und `addFromString()` nimmt dafuer die aktuelle. Zwei
 * Laeufe desselben Beschlusses ergaeben damit zwei verschiedene Dateien,
 * obwohl jedes Blatt darin dasselbe ist — und eine Zusicherung, die das
 * prueft, schluege nur dann fehl, wenn die beiden Laeufe zufaellig ueber eine
 * Sekundengrenze fielen. Genau solche Fehlschlaege glaubt man dem Testlauf
 * nicht mehr. Darum der Tag des Vorgangs: er steht fest, sobald das Schreiben
 * heraus ist.
 */
final readonly class PdfArchive
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $letters Dateiname auf Inhalt
     */
    public static function of(array $letters, DateTimeImmutable $on): string
    {
        $path = tempnam(sys_get_temp_dir(), 'billing');

        if (false === $path) {
            throw new RuntimeException('Das Archiv ließ sich nicht anlegen.');
        }

        $archive = new ZipArchive();
        $archive->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        foreach ($letters as $name => $bytes) {
            $archive->addFromString($name, $bytes);
            $archive->setMtimeName($name, $on->getTimestamp());
        }

        $archive->close();
        $bundle = file_get_contents($path);
        unlink($path);

        return false === $bundle ? '' : $bundle;
    }
}
