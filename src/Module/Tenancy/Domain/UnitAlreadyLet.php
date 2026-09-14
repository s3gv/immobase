<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Die Einheit hat schon ein aktives Mietverhaeltnis.
 *
 * Zwei aktive an derselben Einheit waeren nicht nur fachlich falsch, sie
 * naehmen dem Leerstand seine Grundlage: er wird nicht erfasst, sondern
 * erschlossen — eine Einheit ohne aktives Mietverhaeltnis steht leer. Das
 * traegt nur, wenn „aktiv" je Einheit eindeutig ist.
 *
 * Die letzte Grenze zieht ein partieller eindeutiger Index in der Datenbank.
 * Meist faellt es frueher auf — dann steht die Meldung am Feld, und der Stand
 * des Formulars bleibt erhalten.
 *
 * Zwischen dieser Pruefung und dem Speichern passt aber eine zweite Anfrage.
 * Gewinnt sie, meldet es die Datenbank, und der Entity Manager ist danach
 * geschlossen: nachzuladen, was die Seite zum Zeichnen braucht, geht dann
 * nicht mehr. `whileSaving` unterscheidet die beiden Faelle, damit der
 * Aufrufer den zweiten weiterleiten kann, statt in einen Fehler zu laufen.
 */
final class UnitAlreadyLet extends RuntimeException
{
    private function __construct(string $message, public readonly bool $whileSaving)
    {
        parent::__construct($message);
    }

    /** Beim Nachschlagen aufgefallen — das Formular steht noch. */
    public static function of(int $number): self
    {
        return new self(\sprintf('Die Einheit ist bereits vermietet — Mietverhältnis %d.', $number), false);
    }

    /** Erst beim Speichern aufgefallen: eine andere Anfrage war schneller. */
    public static function inTheMeantime(): self
    {
        return new self('Die Einheit wurde inzwischen anderweitig vermietet.', true);
    }
}
