<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

/**
 * Die Schritte beim Erfassen einer Forderung von Hand.
 *
 * Drei, und wie ueberall speichert jeder sofort. Der erste legt die
 * Forderung an — Einheit, Glaeubiger und der erste Posten; danach steht der
 * Schuldner fest, denn er folgt aus beidem. Der zweite nimmt weitere Posten
 * desselben Schuldners auf, der dritte zeigt, was daraus geworden ist, und
 * fuehrt ins Mahnen.
 *
 * **Warum ueberhaupt ein Ablauf und nicht ein Formular:** der Regelfall sind
 * drei Monatsmieten, und die entstehen nacheinander. Ein Formular mit neun
 * Feldern in drei Spalten waere dieselbe Arbeit ohne die Gewissheit, dass
 * das Eingetragene schon gespeichert ist.
 */
final class ClaimFlow
{
    public const string CLAIM = 'forderung';
    public const string MORE = 'weitere';
    public const string REVIEW = 'pruefen';

    /** Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen englisch. */
    private const array NAMES = [
        self::CLAIM => 'claim',
        self::MORE => 'more',
        self::REVIEW => 'review',
    ];

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::CLAIM, self::MORE, self::REVIEW];
    }

    /** Ein unbekannter Schritt faellt auf den ersten zurueck — Eingabe, kein Fehler. */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::CLAIM;
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

    /**
     * Zurueck geht nur vom letzten Schritt.
     *
     * Der erste hat die Forderung angelegt; ein Zurueck dorthin fuehrte auf
     * ein Formular, das noch eine anlegen wuerde. Vom Pruefen zum Nachtragen
     * dagegen ist ein echter Weg.
     */
    public static function mayGoBack(string $step): bool
    {
        return self::REVIEW === $step;
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
        return self::NAMES[$key] ?? 'claim';
    }
}
