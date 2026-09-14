<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

/**
 * Eine grobe Schaetzung, wie schwer ein Passwort zu erraten ist.
 *
 * Gerechnet wird die Entropie unter der Annahme, ein Angreifer kenne den
 * verwendeten Zeichenvorrat: Laenge mal Logarithmus des Vorrats. Das belohnt
 * Laenge staerker als Sonderzeichen — zwoelf Kleinbuchstaben reichen nicht,
 * ein langer Satz schon.
 *
 * Die Schaetzung ist bewusst grob und weiss nichts ueber Woerterbuecher:
 * "Passwort1234" kaeme rechnerisch gut weg. Genau dafuer gibt es die
 * Pruefung gegen bekannte Leaks daneben — eine Formel kann nicht wissen, was
 * Menschen tatsaechlich waehlen.
 *
 * Selbst geschrieben statt symfony/validator: es waere die einzige
 * Verwendung des ganzen Bauteils.
 */
final readonly class PasswordStrength
{
    /** Ab hier gilt ein Passwort als stark genug. */
    public const int REQUIRED_BITS = 60;

    public static function bits(string $password): float
    {
        $length = mb_strlen($password);

        if (0 === $length) {
            return 0.0;
        }

        return $length * log(self::charsetSize($password), 2);
    }

    public static function isStrongEnough(string $password): bool
    {
        return self::bits($password) >= self::REQUIRED_BITS;
    }

    /**
     * Wie gross der Zeichenvorrat ist, aus dem geschoepft wurde.
     *
     * Nicht die Zahl der *verschiedenen* Zeichen: wer eine Ziffer benutzt,
     * eroeffnet dem Angreifer alle zehn, nicht nur die eine.
     */
    private static function charsetSize(string $password): int
    {
        $size = 0;

        foreach (self::ranges() as $pattern => $count) {
            if (1 === preg_match($pattern, $password)) {
                $size += $count;
            }
        }

        // Alles ausserhalb von ASCII: Umlaute, Akzente, Emoji. Zurueckhaltend
        // gezaehlt, weil niemand weiss, wie gross der Vorrat wirklich ist.
        if (1 === preg_match('/[^\x20-\x7e]/', $password)) {
            $size += 100;
        }

        return max($size, 2);
    }

    /**
     * @return array<string, int>
     */
    private static function ranges(): array
    {
        return [
            '/[a-z]/' => 26,
            '/[A-Z]/' => 26,
            '/[0-9]/' => 10,
            '/[^a-zA-Z0-9\x80-\xff]/' => 33,
        ];
    }
}
