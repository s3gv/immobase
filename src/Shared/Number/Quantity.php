<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Number;

use InvalidArgumentException;

/**
 * Eine Anzahl von Dingen: Geschosse, Stellplaetze, Personen im Haushalt.
 *
 * Nicht negativ, und nach oben begrenzt. Die Obergrenze ist keine Schikane:
 * die Spalten sind SMALLINT, und ohne Grenze endet eine zu grosse Zahl als
 * Datenbankfehler statt als Hinweis am Feld. Was darueber liegt, ist ohnehin
 * kein Gebaeude mehr, sondern ein Vertipper.
 *
 * Liegt in Shared und nicht in einem Modul: „minus drei Stellplaetze" und
 * „minus drei Personen" sind derselbe Fehler, und die Grenze nach oben kommt
 * aus dem Spaltentyp, den alle benutzen.
 */
final class Quantity
{
    /** Der groesste Wert, den ein SMALLINT traegt. */
    private const int MOST = 32767;

    private function __construct()
    {
    }

    public static function orNull(?int $count, string $field): ?int
    {
        return null === $count ? null : self::of($count, $field);
    }

    /**
     * Dieselbe Pruefung, wo die Angabe verpflichtend ist.
     *
     * Sonst muesste jeder Aufrufer, der ohnehin eine Zahl hat, den Rueckgabewert
     * gegen null absichern — und ein `?? 0` dort waere ein stiller Ausweg, wo
     * es keinen Fall gibt.
     */
    public static function of(int $count, string $field): int
    {
        if ($count < 0) {
            throw new InvalidArgumentException($field.' kann nicht negativ sein.');
        }

        if ($count > self::MOST) {
            throw new InvalidArgumentException($field.' ist unglaubwürdig hoch.');
        }

        return $count;
    }
}
