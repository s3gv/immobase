<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

/**
 * Die Schritte eines Mahnschreibens.
 *
 * Drei, nicht vier. Bei der Dauermietrechnung waren vier richtig, weil vier
 * verschiedene Dinge zu pruefen waren; hier gibt es eine echte Eingabe — was
 * gemahnt wird, wie ernst und bis wann.
 */
final class NoticeFlow
{
    public const string CLAIMS = 'forderungen';
    public const string NOTICE = 'schreiben';
    public const string ISSUE = 'ausstellung';

    /** Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen englisch. */
    private const array NAMES = [
        self::CLAIMS => 'claims',
        self::NOTICE => 'notice',
        self::ISSUE => 'issue',
    ];

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::CLAIMS, self::NOTICE, self::ISSUE];
    }

    /** Ein unbekannter Schritt faellt auf den ersten zurueck — Eingabe, kein Fehler. */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::CLAIMS;
    }

    public static function next(string $step): ?string
    {
        $at = array_search($step, self::keys(), true);

        return false === $at ? null : (self::keys()[$at + 1] ?? null);
    }

    public static function previous(string $step): ?string
    {
        $at = array_search($step, self::keys(), true);

        return false === $at || 0 === $at ? null : (self::keys()[$at - 1] ?? null);
    }

    public static function positionOf(string $step): int
    {
        $at = array_search($step, self::keys(), true);

        return false === $at ? 1 : $at + 1;
    }

    public static function count(): int
    {
        return \count(self::keys());
    }

    public static function name(string $key): string
    {
        return self::NAMES[$key] ?? 'claims';
    }
}
