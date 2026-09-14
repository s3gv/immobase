<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Contract;

use App\Shared\Todo\Todo;
use App\Shared\Todo\TodoKind;

/**
 * Die Todos einer Art, fertig sortiert.
 *
 * `hidden` sagt, wie viele nicht dastehen: eine Kachel mit dreissig Zeilen
 * ist keine Uebersicht mehr. Was nicht passt, bleibt zaehlbar.
 */
final readonly class TodoGroup
{
    /**
     * @param list<Todo> $shown
     */
    public function __construct(
        public TodoKind $kind,
        public array $shown,
        public int $hidden,
    ) {
    }
}
