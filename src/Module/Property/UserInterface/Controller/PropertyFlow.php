<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Domain\Property;

/**
 * Die Schritte beim Anlegen und Bearbeiten eines Objekts.
 *
 * Kein FlowState und kein Sitzungsspeicher wie bei den Stammdaten: hier
 * speichert jeder Schritt sofort in die Datenbank. Damit ist jeder Schritt
 * jederzeit erreichbar — es gibt keinen „noch nicht besuchten" Schritt, weil
 * es keinen Zwischenstand gibt, der verloren gehen koennte.
 *
 * Der Preis dafuer steht am Objekt: es ist ab dem ersten Schritt ein Entwurf
 * in der Liste. Der Gewinn ist, dass man morgen weitermachen kann.
 */
final class PropertyFlow
{
    public const string NAME = 'bezeichnung';
    public const string ADDRESS = 'anschrift';
    public const string BUILDING = 'gebaeude';
    public const string HEATING = 'heizung';
    public const string REGISTRY = 'grundbuch';
    public const string ACCOUNTING = 'abrechnung';
    public const string BANK = 'bankkonto';
    public const string MEA = 'anteile';
    public const string UNITS = 'einheiten';

    private function __construct()
    {
    }

    /**
     * Alle Schritte eines Objekts.
     *
     * „Anteile" entfaellt bei reiner Mietverwaltung — dort gibt es keine
     * Miteigentumsanteile, und ein Schritt, der nichts bedeutet, ist
     * schlimmer als keiner.
     *
     * Vor dem ersten Speichern stehen trotzdem alle neun da, blass und
     * nicht anklickbar: „Schritt 1 von 8" und danach „Schritt 2 von 9" waere
     * ein Weg, der beim Gehen laenger wird.
     *
     * @return list<string>
     */
    public static function keys(?Property $property): array
    {
        $keys = [
            self::NAME, self::ADDRESS, self::BUILDING,
            self::HEATING, self::REGISTRY, self::ACCOUNTING, self::BANK,
        ];

        if (null === $property || $property->modes()->needMea()) {
            $keys[] = self::MEA;
        }

        return [...$keys, self::UNITS];
    }

    public static function known(string $requested, ?Property $property): string
    {
        return \in_array($requested, self::keys($property), true) ? $requested : self::NAME;
    }

    public static function next(string $step, Property $property): ?string
    {
        $keys = self::keys($property);
        $at = array_search($step, $keys, true);

        return false === $at ? null : ($keys[$at + 1] ?? null);
    }

    public static function previous(string $step, Property $property): ?string
    {
        $keys = self::keys($property);
        $at = array_search($step, $keys, true);

        return false === $at || 0 === $at ? null : ($keys[$at - 1] ?? null);
    }

    public static function isLast(string $step, Property $property): bool
    {
        return null === self::next($step, $property);
    }

    /** Die Stelle im Ablauf, ab eins gezaehlt. */
    public static function positionOf(string $step, ?Property $property): int
    {
        $at = array_search($step, self::keys($property), true);

        return false === $at ? 1 : $at + 1;
    }

    public static function count(?Property $property): int
    {
        return \count(self::keys($property));
    }
}
