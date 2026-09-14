<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

/**
 * Die Schritte eines Wirtschaftsplans.
 *
 * Sechs, und jeder speichert sofort — dieselbe Gestalt wie bei der Abrechnung
 * und aus demselben Grund: einen Plan stellt man nicht in einem Zug auf, und
 * wer ihn liegen laesst, soll ihn wiederfinden.
 *
 * Die Erhaltungsruecklage bekommt einen eigenen Schritt, obwohl sie technisch
 * nur eine weitere Zeile ist. Ueber sie wird nach § 28 Abs. 1 WEG **getrennt**
 * beschlossen; zwischen Muellabfuhr und Versicherung gequetscht saehe sie aus
 * wie eine Kostenart unter anderen.
 *
 * Die letzten beiden Schritte sind der Weg ueber die Versammlung, und er ist
 * der Grund, warum ein Plan mehr Schritte hat als eine Abrechnung:
 *
 * * **Vorlage** — die Zahlen gehen heraus, damit die Eigentuemer sie vor der
 *   Versammlung lesen koennen. Ein Plan, der erst mit dem Beschluss auf Papier
 *   erschiene, koennte gar nicht beschlossen werden.
 * * **Beschluss** — was die Versammlung entschieden hat, wird festgehalten,
 *   und damit gelten die Vorschuesse.
 */
final class PlanFlow
{
    public const string BASICS = 'wirtschaftsplan';
    public const string POSITIONS = 'positionen';
    public const string RESERVE = 'ruecklage';
    public const string ADVANCES = 'vorschuesse';
    public const string PROPOSAL = 'vorlage';
    public const string DECISION = 'beschluss';

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [
            self::BASICS,
            self::POSITIONS,
            self::RESERVE,
            self::ADVANCES,
            self::PROPOSAL,
            self::DECISION,
        ];
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
