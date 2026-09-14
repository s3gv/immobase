<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Todo;

/**
 * Wie dringend ein Todo ist.
 *
 * Entscheidet die Farbe des Punktes und die Reihenfolge innerhalb einer
 * Gruppe — nicht die Gruppe selbst. Ein Entwurf kann dringend sein und eine
 * Frist gelassen; wo etwas herkommt und wie eilig es ist, sind zwei Fragen.
 *
 * Dieselben drei Toene wie ueberall in der Oberflaeche, damit Rot in der
 * Uebersicht dasselbe heisst wie Rot auf einer Modulseite.
 */
enum Urgency: string
{
    case Danger = 'danger';
    case Warning = 'warning';
    case Neutral = 'neutral';

    /** Je hoeher, desto weiter oben. */
    public function weight(): int
    {
        return match ($this) {
            self::Danger => 2,
            self::Warning => 1,
            self::Neutral => 0,
        };
    }
}
