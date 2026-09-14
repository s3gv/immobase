<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

/**
 * Die Schritte eines Budgetplans.
 *
 * Fuenf, und die Reihenfolge ist die der Fragen, die eine Versammlung stellt:
 * Was wollen wir? Was kostet es? Woher kommt das Geld? Wer traegt es? Und was
 * haben wir beschlossen?
 *
 * Die **Verteilung** steht vor dem Beschluss und nicht darin, obwohl der
 * Verteilerkreis erst aus dem Abstimmungsergebnis folgt: gewaehlt wird hier
 * der Schluessel, und der gehoert in die Vorlage. Wer zahlt, entscheidet die
 * Versammlung — das steht dann im letzten Schritt.
 */
final class BudgetFlow
{
    public const string MEASURE = 'massnahme';
    public const string COSTS = 'kosten';
    public const string FUNDING = 'finanzierung';
    public const string SHARING = 'verteilung';
    public const string DECISION = 'beschluss';

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::MEASURE, self::COSTS, self::FUNDING, self::SHARING, self::DECISION];
    }

    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::MEASURE;
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
