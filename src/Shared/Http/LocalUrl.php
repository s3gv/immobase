<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Eine Adresse aus der Anfrage, zu der weitergeleitet werden darf.
 *
 * Ein Ablauf, der jemanden nach dem Speichern dorthin zurueckbringt, wo er
 * hergekommen ist, muss diese Adresse entgegennehmen — und genau da entsteht
 * die offene Weiterleitung: wer `?weiter=https://woanders` unterschiebt,
 * schickt den Angemeldeten mit einem Klick auf eine fremde Seite, die
 * aussieht wie diese.
 *
 * Erlaubt ist deshalb nur ein Pfad auf diesem Server: beginnt mit einem
 * Schraegstrich, aber nicht mit zweien (`//fremde.example` waere eine
 * vollstaendige Adresse ohne Schema) und ohne Schema oder Steuerzeichen.
 */
final class LocalUrl
{
    private function __construct()
    {
    }

    public static function orNull(?string $candidate): ?string
    {
        $value = trim($candidate ?? '');

        if ('' === $value || !str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        // Ein Backslash steht in manchen Browsern fuer einen Schraegstrich;
        // "/\fremde.example" waere dort ebenfalls eine fremde Adresse.
        if (str_contains($value, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }

        return $value;
    }
}
