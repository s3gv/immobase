<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract\Event;

use App\Shared\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Diese Einheit verlaesst die Verwaltung — verkauft, abgegeben, nicht mehr
 * unsere.
 *
 * Bei Sondereigentumsverwaltung ist das der Normalfall: dort verwaltet man
 * einzelne Einheiten und verliert sie einzeln, ohne dass das Objekt endet.
 */
final readonly class UnitClosed implements DomainEvent
{
    public function __construct(
        public string $unitId,
        public DateTimeImmutable $effectiveOn,
    ) {
    }
}
