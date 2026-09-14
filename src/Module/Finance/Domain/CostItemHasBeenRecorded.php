<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Zu dieser Kostenposition sind schon Betraege erfasst.
 *
 * Geloescht wird nur, was nie in Kraft war — der Entwurf, die Fehleingabe.
 * Sobald ein Jahreswert daran haengt, kann die Position Grundlage einer
 * Abrechnung sein: abgerechnet wird das vergangene Jahr, manchmal das
 * vorletzte. Dann ist Beenden die richtige Antwort und Loeschen die falsche.
 */
final class CostItemHasBeenRecorded extends DomainException
{
}
