<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Identity;

/**
 * Eine Kennung in der ueblichen Schreibweise, mit Bindestrichen.
 *
 * PostgreSQL gibt eine UUID-Spalte immer mit Bindestrichen zurueck. Wer eine
 * Kennung als 32 blosse Hexziffern erzeugt, hat danach zwei Schreibweisen
 * derselben Sache: die frisch erzeugte und die frisch geladene. Solange sie
 * nur in der Datenbank steht, faellt das nicht auf — sie normalisiert beim
 * Vergleichen.
 *
 * Auffallen tut es, sobald die Kennung in PHP verglichen wird: als
 * Array-Schluessel, in einer Adresszeile, in einem Formularfeld. Dann trifft
 * `$briefs[$id]` nicht, und die Seite zeigt die Kennung statt des Namens.
 * Genau das ist zweimal passiert — bei den Rollen und beim Rueckweg aus dem
 * Stammdaten-Ablauf.
 */
final class Uuid
{
    private function __construct()
    {
    }

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
