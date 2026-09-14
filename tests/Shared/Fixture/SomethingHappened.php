<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Fixture;

use App\Shared\Event\DomainEvent;

/**
 * Ereignis nur für den Nachweis, dass der Event-Bus trägt.
 *
 * Es gibt bewusst kein Produktions-Event: solange nur ein Fachmodul existiert,
 * kann es keinen Zyklus geben, den ein Event auflösen müsste.
 */
final readonly class SomethingHappened implements DomainEvent
{
    public function __construct(public string $unitId)
    {
    }
}
