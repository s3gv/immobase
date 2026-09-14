<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

/**
 * Die Schritte eines Vermoegensberichts.
 *
 * Vier, und damit der kuerzeste Ablauf im Modul — es gibt nichts zu verteilen
 * und nichts zu beschliessen. § 28 Abs. 4 WEG verlangt eine Auskunft.
 *
 * Die Reihenfolge folgt dem Gesetz: erst der Stand der Erhaltungsruecklage,
 * dann die Aufstellung des uebrigen Vermoegens. Der Ruecklagenschritt hat kein
 * einziges Eingabefeld und steht trotzdem eigens da — was die Anwendung selbst
 * weiss, soll man sehen, bevor man sich fragt, warum man es nicht eintragen
 * muss.
 */
final class AssetReportFlow
{
    public const string BASICS = 'vermoegensbericht';
    public const string RESERVE = 'ruecklage';
    public const string ASSETS = 'vermoegen';
    public const string RELEASE = 'herausgabe';

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::BASICS, self::RESERVE, self::ASSETS, self::RELEASE];
    }

    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::BASICS;
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

    /** Die Stelle im Ablauf, ab eins gezaehlt. */
    public static function positionOf(string $step): int
    {
        $at = array_search($step, self::keys(), true);

        return false === $at ? 1 : $at + 1;
    }

    public static function count(): int
    {
        return \count(self::keys());
    }
}
