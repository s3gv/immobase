<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Der Schluessel wird von mindestens einer Kostenposition benutzt.
 *
 * Ihn zu entfernen hiesse, eine Position ohne Verteilung zurueckzulassen —
 * und die Abrechnung des vergangenen Jahres wuesste nicht mehr, wie sie
 * verteilt hat.
 */
final class DistributionKeyIsInUse extends DomainException
{
}
