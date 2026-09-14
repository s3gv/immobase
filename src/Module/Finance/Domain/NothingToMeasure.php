<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * An dieser Kostenposition gibt es nichts zu messen.
 *
 * Mengen je Einheit gehoeren zu einem Verbrauchsschluessel. Steht dort
 * „nach Flaeche", verteilt die Abrechnung nach Flaeche — erfasste Mengen
 * daneben waeren Zahlen, die niemand mehr benutzt und die trotzdem
 * aussehen, als gaelten sie.
 */
final class NothingToMeasure extends DomainException
{
}
