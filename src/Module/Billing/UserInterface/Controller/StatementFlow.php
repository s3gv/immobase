<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

/**
 * Die Schritte einer Abrechnung.
 *
 * Fuenf, und jeder speichert sofort — wie beim Objekt und aus demselben
 * Grund: eine Abrechnung ist Arbeit fuer einen Nachmittag, und wer sie
 * liegen laesst, soll sie wiederfinden.
 *
 * Objekt und Arten stehen zusammen im ersten Schritt. Erst das Objekt sagt,
 * welche Arten ueberhaupt etwas ergeben; sie davor zu erfragen hiesse, die
 * Antwort drei Schritte lang schuldig zu bleiben.
 */
final class StatementFlow
{
    public const string BASICS = 'abrechnung';
    public const string ADVANCES = 'vorauszahlungen';
    public const string COSTS = 'kosten';
    public const string RECIPIENTS = 'empfaenger';
    public const string PREVIEW = 'vorschau';

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::BASICS, self::ADVANCES, self::COSTS, self::RECIPIENTS, self::PREVIEW];
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
