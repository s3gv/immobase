<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use RuntimeException;

/**
 * Als Eigentuemer kam eine Kennung an, zu der es keinen Stammdatensatz gibt.
 *
 * Die Datenbank haette es ohnehin abgelehnt — property_unit_owner.party_id
 * traegt einen Fremdschluessel auf party. Hier faellt es frueher und
 * verstaendlicher auf: der Schritt kommt mit einer Meldung am Feld zurueck
 * statt mit einem Datenbankfehler mitten auf der Seite, und es ist noch
 * nichts halb uebernommen.
 */
final class UnknownOwner extends RuntimeException
{
    public static function of(string $partyId): self
    {
        return new self(\sprintf('Zur Kennung „%s" gibt es keinen Stammdatensatz.', $partyId));
    }
}
