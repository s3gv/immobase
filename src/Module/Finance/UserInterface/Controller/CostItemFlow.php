<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

/**
 * Die Schritte beim Anlegen und Bearbeiten einer Kostenposition.
 *
 * Erst die Zuordnung, dann die Faelligkeit, dann die Betraege, dann der
 * Rest — wie ueberall speichert jeder Schritt sofort. Anders als beim Objekt und beim
 * Mietverhaeltnis gibt es keinen Entwurfszustand: eine Kostenposition
 * blockiert nichts und muss nichts abschliessen. Sie steht ab dem ersten
 * Schritt in der Liste.
 *
 * Dieselben Abschnitte tragen die Detailseite: wer den Ablauf kennt, findet
 * sich dort zurecht.
 */
final class CostItemFlow
{
    public const string ASSIGNMENT = 'zuordnung';
    public const string DUE = 'faelligkeit';
    public const string AMOUNTS = 'betraege';
    public const string REST = 'sonstiges';

    /** Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen englisch. */
    private const array NAMES = [
        self::DUE => 'due',
        self::AMOUNTS => 'amounts',
        self::REST => 'rest',
    ];

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::ASSIGNMENT, self::DUE, self::AMOUNTS, self::REST];
    }

    /**
     * Ein unbekannter Schritt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::ASSIGNMENT;
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

    public static function name(string $key): string
    {
        return self::NAMES[$key] ?? 'assignment';
    }
}
