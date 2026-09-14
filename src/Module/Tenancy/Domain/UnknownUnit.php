<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Als Einheit kam eine Kennung an, zu der es keine gibt.
 *
 * Die Datenbank haette es ohnehin abgelehnt — tenancy.unit_id traegt einen
 * Fremdschluessel auf property_unit. Hier faellt es frueher und
 * verstaendlicher auf: der Schritt kommt mit einer Meldung am Feld zurueck
 * statt mit einem Datenbankfehler mitten auf der Seite.
 */
final class UnknownUnit extends RuntimeException
{
    public static function of(string $unitId): self
    {
        return new self(\sprintf('Zur Kennung „%s" gibt es keine Einheit.', $unitId));
    }
}
