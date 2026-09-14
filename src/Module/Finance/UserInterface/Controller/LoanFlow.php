<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

/**
 * Die Schritte beim Anlegen und Bearbeiten eines Darlehens.
 *
 * Zwei, und die Reihenfolge hat einen Grund: erst wer es gegeben hat und
 * wofuer, dann was vereinbart ist. Die Konditionen sind die Angaben, aus
 * denen sich der Tilgungsplan rechnet — sie stehen zusammen auf einer Seite,
 * weil man sie zusammen von der Bank bekommt.
 *
 * Was **unterwegs** passiert — Sondertilgung, neuer Zins — ist kein Schritt:
 * es passiert nach dem Anlegen, oft jahrelang danach. Das steht auf der
 * Detailseite, wie die Bewegungen der Ruecklage.
 */
final class LoanFlow
{
    public const string BASICS = 'eckdaten';
    public const string TERMS = 'konditionen';

    /** Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen englisch. */
    private const array NAMES = [
        self::BASICS => 'basics',
        self::TERMS => 'terms',
    ];

    private function __construct()
    {
    }

    /**
     * @return non-empty-list<string>
     */
    public static function keys(): array
    {
        return [self::BASICS, self::TERMS];
    }

    /** Ein unbekannter Schritt faellt auf den ersten zurueck — Eingabe, kein Fehler. */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::BASICS;
    }

    public static function next(string $step): ?string
    {
        return self::BASICS === $step ? self::TERMS : null;
    }

    public static function previous(string $step): ?string
    {
        return self::TERMS === $step ? self::BASICS : null;
    }

    public static function positionOf(string $step): int
    {
        return self::TERMS === $step ? 2 : 1;
    }

    public static function count(): int
    {
        return \count(self::keys());
    }

    public static function name(string $key): string
    {
        return self::NAMES[$key] ?? 'basics';
    }
}
