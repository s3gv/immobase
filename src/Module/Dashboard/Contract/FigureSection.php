<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Contract;

use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;

/**
 * Die Zahlen einer Gruppe, in der Reihenfolge, in der sie gemeldet wurden.
 *
 * Eine Gruppe, in der keine Zahl steht, gibt es nicht: leere Abschnitte sind
 * keine Auskunft, sondern eine Luecke, die nach einem Fehler aussieht.
 */
final readonly class FigureSection
{
    /**
     * @param list<Figure> $figures
     */
    public function __construct(
        public FigureGroup $group,
        public array $figures,
    ) {
    }
}
