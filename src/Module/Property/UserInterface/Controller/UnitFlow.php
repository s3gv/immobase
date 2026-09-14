<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Domain\Unit;

/**
 * Die Abschnitte einer Einheit — beim Bearbeiten wie beim Ansehen dieselben.
 *
 * „Mieter" stand hier einmal als leerer Abschnitt. Seit es Mietverhaeltnisse
 * gibt, waere er eine zweite Stelle fuer dieselbe Sache: das Mietverhaeltnis
 * hat seine eigene Seite, und die Einheit verweist darauf. Ein Abschnitt, der
 * nur eine Liste von Verweisen traegt, ist ein Umweg.
 */
final class UnitFlow
{
    public const string BASICS = 'angaben';
    public const string OWNERS = 'eigentuemer';
    public const string DETAIL = 'details';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function editable(Unit $unit): array
    {
        $keys = [self::BASICS];

        // Ohne Miteigentumsanteile gibt es auch keine Eigentuemer zu erfassen:
        // in reiner Mietverwaltung gehoert das Haus einem, und das steht am
        // Vertrag, nicht an der Einheit.
        if ($unit->property()->modes()->needMea()) {
            $keys[] = self::OWNERS;
        }

        return [...$keys, self::DETAIL];
    }

    /**
     * Ein unbekannter Abschnitt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public static function known(string $requested, Unit $unit): string
    {
        return \in_array($requested, self::editable($unit), true) ? $requested : self::BASICS;
    }

    public static function next(string $step, Unit $unit): ?string
    {
        $keys = self::editable($unit);
        $at = array_search($step, $keys, true);

        return false === $at ? null : ($keys[$at + 1] ?? null);
    }

    public static function previous(string $step, Unit $unit): ?string
    {
        $keys = self::editable($unit);
        $at = array_search($step, $keys, true);

        return false === $at || 0 === $at ? null : ($keys[$at - 1] ?? null);
    }

    public static function positionOf(string $step, Unit $unit): int
    {
        $at = array_search($step, self::editable($unit), true);

        return false === $at ? 1 : $at + 1;
    }
}
