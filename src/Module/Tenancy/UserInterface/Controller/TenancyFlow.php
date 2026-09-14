<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

/**
 * Die Schritte beim Anlegen und Bearbeiten eines Mietverhaeltnisses.
 *
 * Wie beim Objekt speichert jeder Schritt sofort in die Datenbank. Damit ist
 * jeder Schritt jederzeit erreichbar — es gibt keinen „noch nicht besuchten"
 * Schritt, weil es keinen Zwischenstand gibt, der verloren gehen koennte.
 *
 * Der Preis steht am Mietverhaeltnis: es ist ab dem ersten Schritt inaktiv in
 * der Liste. Der Gewinn ist, dass man morgen weitermachen kann — und dass ein
 * liegengelassener Ablauf die Einheit nicht blockiert.
 *
 * Dieselben Abschnitte tragen spaeter die Mietseite: wer den Ablauf kennt,
 * findet sich dort zurecht.
 */
final class TenancyFlow
{
    public const string BASICS = 'mietverhaeltnis';
    public const string TENANTS = 'mieter';
    public const string TERM = 'laufzeit';
    public const string RENT = 'miete';
    public const string DEPOSIT = 'kaution';
    public const string NOTE = 'notiz';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::BASICS, self::TENANTS, self::TERM, self::RENT, self::DEPOSIT, self::NOTE];
    }

    /**
     * Ein unbekannter Schritt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
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

    /**
     * Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen
     * englisch. Hier laufen sie zusammen.
     */
    public static function name(string $key): string
    {
        return match ($key) {
            self::TENANTS => 'tenants',
            self::TERM => 'term',
            self::RENT => 'rent',
            self::DEPOSIT => 'deposit',
            self::NOTE => 'note',
            default => 'basics',
        };
    }
}
