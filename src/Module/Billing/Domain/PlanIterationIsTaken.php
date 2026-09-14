<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;
use Throwable;

/**
 * Dieselbe Korrektur hat im selben Augenblick jemand anderes angelegt.
 *
 * Der eindeutige Index ueber Nummer und Iteration faengt das ab. Die
 * Anwendung gibt die lesbare Absage, die Datenbank bleibt die letzte Linie.
 */
final class PlanIterationIsTaken extends RuntimeException
{
    public static function because(Throwable $clash): self
    {
        return new self('billing.plan.error.correction_taken', 0, $clash);
    }
}
