<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Beenden ohne Mietende gibt es nicht.
 *
 * Ein Mietverhaeltnis, das beendet ist, aber nicht sagt wann, traegt einen
 * Zeitraum ohne Ende — und ist damit fuer jede tagesgenaue Abrechnung
 * wertlos. Genau dann faellt es auf: zwei Jahre spaeter, wenn niemand mehr
 * weiss, welcher Tag gemeint war.
 */
final class TenancyNeedsAnEnd extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Zum Beenden gehört ein Mietende.');
    }
}
