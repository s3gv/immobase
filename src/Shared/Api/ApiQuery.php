<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Api;

/**
 * Was ein Plugin von einer Liste sehen will.
 *
 * Heute nur die Seite. Als Objekt und nicht als Zahl, damit v1 wachsen kann,
 * ohne dass jede Ressource ihre Signatur aendert — und innerhalb von v1 wird
 * nur erweitert.
 */
final readonly class ApiQuery
{
    public function __construct(public int $page = 1)
    {
    }
}
