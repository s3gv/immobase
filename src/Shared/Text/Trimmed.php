<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Text;

use InvalidArgumentException;

/**
 * Eingegebener Text ohne umgebende Leerzeichen.
 *
 * Ein Feld, in dem nur Leerzeichen stehen, ist leer — nur sieht man es ihm
 * nicht an. Ohne diese Pruefung entstehen Datensaetze mit einem Namen aus
 * einem Leerzeichen, die in jeder Liste als Luecke erscheinen.
 *
 * Steht in Shared, weil jedes Fachmodul dieselbe Frage hat.
 */
final class Trimmed
{
    private function __construct()
    {
    }

    /**
     * @param non-empty-string $field Feldname für die Fehlermeldung
     *
     * @return non-empty-string
     */
    public static function required(string $value, string $field): string
    {
        $trimmed = self::strip($value);

        if ('' === $trimmed) {
            throw new InvalidArgumentException(\sprintf('%s darf nicht leer sein.', $field));
        }

        return $trimmed;
    }

    /**
     * @return non-empty-string|null
     */
    public static function orNull(?string $value): ?string
    {
        $trimmed = self::strip($value ?? '');

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Entfernt auch die Leerzeichen, die trim() stehen laesst.
     *
     * trim() kennt nur die sieben ASCII-Zeichen. Aus Textverarbeitungen und
     * Webseiten kommen aber geschuetzte und schmale Leerzeichen mit, dazu die
     * Byte-Reihenfolge-Marke. Ein Name, der nur daraus besteht, waere sonst
     * ein gueltiger Name.
     */
    private static function strip(string $value): string
    {
        return (string) preg_replace(
            '/^[\s\x{00A0}\x{202F}\x{2007}\x{FEFF}]+|[\s\x{00A0}\x{202F}\x{2007}\x{FEFF}]+$/Du',
            '',
            $value,
        );
    }
}
