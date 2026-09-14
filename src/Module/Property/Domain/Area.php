<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Number\Decimals;
use InvalidArgumentException;

/**
 * Eine Flaechenangabe in Quadratmetern, als Dezimalzeichenkette.
 *
 * Keine eigene Klasse mit Rechenwerk: gerechnet wird mit Flaechen erst in der
 * Abrechnung, und dort mit demselben Bruchwerk wie beim Geld. Hier geht es nur
 * darum, dass „72,5" und „72.5" dasselbe ergeben und „viel" gar nichts.
 *
 * Dieselbe Regel gilt fuer die Zimmerzahl. Sie ist keine Flaeche, aber
 * derselbe Fall: eine Angabe mit hoechstens zwei Nachkommastellen, die
 * uebernommen und nicht nachgerechnet wird.
 */
final class Area
{
    private const int MAX_DIGITS = 8;

    private function __construct()
    {
    }

    /** Eine Zahl mit hoechstens zwei Nachkommastellen — oder null. */
    public static function orNull(?string $input): ?string
    {
        // Tippen darf man es, wie man es liest: „1.240,5“ und
        // „1,240.5“ meinen dasselbe wie „1240.5“.
        $clean = Decimals::normalise($input ?? '');

        if ('' === $clean) {
            return null;
        }

        if (1 !== preg_match('/^\d{1,'.self::MAX_DIGITS.'}(\.\d{1,2})?$/D', $clean)) {
            throw new InvalidArgumentException(\sprintf('Eine Fläche ist eine Zahl mit höchstens zwei Nachkommastellen, „%s" ist keine.', $input ?? ''));
        }

        return $clean;
    }
}
