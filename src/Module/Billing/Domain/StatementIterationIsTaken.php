<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;
use Throwable;

/**
 * Diese Iteration gibt es schon.
 *
 * Zwei Menschen klicken im selben Augenblick auf „Korrektur erstellen", oder
 * jemand schickt die Korrektur einer laengst ueberholten Iteration ab. Beide
 * wollen dieselbe Nummer mit derselben Iteration anlegen; der eindeutige
 * Index laesst nur eine durch.
 *
 * Der zweite bekommt eine Antwort und keinen Datenbankfehler: er hat nichts
 * falsch gemacht, er war nur der zweite.
 */
final class StatementIterationIsTaken extends RuntimeException
{
    public static function because(Throwable $cause): self
    {
        return new self('billing.error.correction_taken', 0, $cause);
    }
}
