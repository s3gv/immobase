<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Als Mieter kam eine Kennung an, zu der es keinen Stammdatensatz gibt.
 *
 * Die Datenbank haette es ohnehin abgelehnt — tenancy_tenant.party_id
 * verweist auf party. Hier faellt es frueher auf, und vor der ersten
 * Aenderung.
 */
final class UnknownTenant extends RuntimeException
{
    public static function of(string $partyId): self
    {
        return new self(\sprintf('Zur Kennung „%s" gibt es keinen Stammdatensatz.', $partyId));
    }
}
