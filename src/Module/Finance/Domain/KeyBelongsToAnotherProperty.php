<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Ein eigener Verteilerschluessel gehoert zu seinem Objekt.
 *
 * „Anteile Tiefgarage" aus einem anderen Haus verteilt auf Einheiten, die es
 * hier nicht gibt. Die Systemschluessel sind davon ausgenommen: sie gehoeren
 * zu keinem Objekt und gelten ueberall.
 */
final class KeyBelongsToAnotherProperty extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dieser Verteilerschlüssel gehört zu einem anderen Objekt.');
    }
}
