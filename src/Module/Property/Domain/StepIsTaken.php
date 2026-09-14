<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use RuntimeException;

/**
 * Zu diesem Tag gibt es schon einen Eintrag.
 *
 * Zwei Personenzahlen ab demselben Tag waeren zwei Antworten auf eine Frage.
 * Welche gilt, koennte danach niemand mehr sagen — auch die Abrechnung nicht.
 */
final class StepIsTaken extends RuntimeException
{
    public static function onThatDay(): self
    {
        return new self('property.unit.household.taken');
    }
}
