<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Ein Mietverhaeltnis ohne Mieter laesst sich nicht aktiv setzen.
 *
 * Waehrend der Erfassung darf es leer bleiben — deshalb beginnt es inaktiv.
 * Aktiv heisst aber: die Einheit ist vermietet, und zwar an jemanden.
 */
final class TenancyNeedsATenant extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ein Mietverhältnis braucht mindestens einen Mieter.');
    }
}
