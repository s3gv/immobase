<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

/**
 * Die Schritte einer Dauermietrechnung.
 *
 * Vier, und zwei davon ohne ein einziges Eingabefeld — dieselbe Gestalt wie
 * der Vermoegensbericht, und aus demselben Grund: was die Anwendung selbst
 * weiss, soll man sehen, bevor man sich fragt, warum man es nicht eintragen
 * muss.
 *
 * Fast alles steht schon irgendwo. Die Miete steht im Mietverhaeltnis, der
 * Vermieter an der Einheit, der Mieter am Vertrag, das Konto am Objekt.
 * Einzutragen ist nur, **ab wann** die Rechnung gilt — der Rest ist
 * nachsehen und ausstellen.
 */
final class RentInvoiceFlow
{
    public const string BASICS = 'rechnung';
    public const string AMOUNTS = 'betraege';
    public const string PARTIES = 'empfaenger';
    public const string ISSUE = 'ausstellung';

    /** Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen englisch. */
    private const array NAMES = [
        self::BASICS => 'basics',
        self::AMOUNTS => 'amounts',
        self::PARTIES => 'parties',
        self::ISSUE => 'issue',
    ];

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::BASICS, self::AMOUNTS, self::PARTIES, self::ISSUE];
    }

    /** Ein unbekannter Schritt faellt auf den ersten zurueck — Eingabe, kein Fehler. */
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
        return self::NAMES[$key] ?? 'basics';
    }
}
