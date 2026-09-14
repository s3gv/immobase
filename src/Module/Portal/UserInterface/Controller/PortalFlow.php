<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

/**
 * Die Bereiche des Portals.
 *
 * Fuenf, und sie sind kein Weg von vorn nach hinten, sondern eine Ablage:
 * jeder steht fuer sich, keiner setzt einen anderen voraus. Die Gestalt ist
 * dieselbe wie im Verwalterbereich — wer ein Formular in ImmoBase kennt,
 * kennt alle —, nur ohne Haupt-Nav daneben.
 *
 * Vier davon zeigen Daten und liegen unter derselben Route. „Anfragen" ist
 * der fuenfte und hat eigene Adressen, weil dort etwas entsteht: eine Liste,
 * ein Formular, ein Gespraech. Er steht trotzdem in derselben Reihe — fuer
 * den Lesenden ist es ein Bereich wie die anderen.
 */
final class PortalFlow
{
    public const string DATA = 'daten';
    public const string PROPERTIES = 'objekte';
    public const string UNITS = 'einheiten';
    public const string TENANCIES = 'mietverhaeltnisse';
    public const string ENQUIRIES = 'anfragen';

    /** Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen englisch. */
    private const array NAMES = [
        self::DATA => 'data',
        self::PROPERTIES => 'properties',
        self::UNITS => 'units',
        self::TENANCIES => 'tenancies',
        self::ENQUIRIES => 'enquiries',
    ];

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::DATA, self::PROPERTIES, self::UNITS, self::TENANCIES, self::ENQUIRIES];
    }

    /** Ein unbekannter Bereich faellt auf den ersten zurueck — Eingabe, kein Fehler. */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::DATA;
    }

    public static function name(string $key): string
    {
        return self::NAMES[$key] ?? 'data';
    }
}
