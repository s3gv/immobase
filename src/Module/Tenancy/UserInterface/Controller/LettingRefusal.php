<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Domain\UnitAlreadyLet;
use App\Module\Tenancy\Domain\UnitLetInThatPeriod;

/**
 * Welche Absage es ist — und wie sie heisst.
 *
 * Zwei Regeln halten eine Einheit frei: hoechstens ein aktives
 * Mietverhaeltnis, und keine zwei mit ueberlappender Laufzeit. Jede kann vor
 * dem Speichern auffallen oder erst dabei — vier Meldungen also, und die
 * Zuordnung steht hier einmal statt in jedem Controller neu.
 */
final class LettingRefusal
{
    private function __construct()
    {
    }

    public static function keyFor(UnitAlreadyLet|UnitLetInThatPeriod $problem): string
    {
        if ($problem instanceof UnitLetInThatPeriod) {
            return $problem->whileSaving ? 'tenancy.error.period_let_meanwhile' : 'tenancy.error.period_let';
        }

        return $problem->whileSaving ? 'tenancy.error.unit_let_meanwhile' : 'tenancy.error.unit_let';
    }
}
