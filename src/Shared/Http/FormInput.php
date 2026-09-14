<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Number\WholeNumber;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Werte aus einem Formular, die kein eigenes Wertobjekt haben.
 *
 * Symfonys `getInt()` macht aus „abc" eine 0 und aus „ja" auch — eine stille
 * Null ist schlimmer als eine Meldung. Hier kommt entweder eine Zahl heraus
 * oder eine Ausnahme, und aus einem leeren Feld null.
 *
 * Dieselbe Bauart wie {@see \App\Shared\Time\DateInput}: die Umsetzung kennt
 * den Request, die Domaene bekommt fertige Werte.
 */
final class FormInput
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function intOrNull(Request $request, string $field): ?int
    {
        return WholeNumber::orNull($request->request->getString($field));
    }

    /**
     * Eine Zahl aus der Adresszeile — nachsichtig.
     *
     * Was dort steht, hat jemand getippt oder ein alter Link mitgebracht.
     * „?seite=" und „?seite=abc" sind keine Angabe und kein Fehler; Symfonys
     * `getInt()` beantwortet beides mit 400, und eine Liste, die sich an
     * ihrer eigenen Adresszeile verschluckt, ist kaputt.
     *
     * Deshalb hier nachsichtig und nicht wie bei einem Formularfeld: dort
     * hat jemand etwas eingetippt und soll erfahren, dass es nicht geht.
     */
    public static function queryIntOrNull(Request $request, string $field): ?int
    {
        $value = trim($request->query->getString($field));

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Drei Zustaende: ja, nein, keine Angabe.
     *
     * Ein Auswahlfeld mit drei Moeglichkeiten ist kein Kaestchen: „weiss ich
     * nicht" ist eine Antwort und nicht dasselbe wie „nein".
     */
    public static function yesNoOrNull(Request $request, string $field): ?bool
    {
        return match ($request->request->getString($field)) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }
}
