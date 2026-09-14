<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Eine Angabe, ohne die sich nicht verteilen laesst.
 *
 * Fehlt eine Flaeche, ein Miteigentumsanteil, eine Personenzahl oder ein
 * Verbrauch, wird daraus **keine Null**. Eine Null verteilt still um: die
 * betroffene Einheit zahlt nichts, und alle anderen zahlen ihren Teil mit,
 * ohne dass es jemandem auffaellt.
 *
 * Stattdessen sammelt die Berechnung die Luecke, die Vorschau zeigt sie, und
 * die Freigabe bleibt gesperrt, solange eine dasteht. Ein Halt ist besser als
 * eine falsche Zahl.
 */
final readonly class MissingFigure
{
    public function __construct(
        public string $unitId,
        public string $unitLabel,
        public string $costKind,
        /** Uebersetzungsschluessel: was genau fehlt. */
        public string $whatKey,
    ) {
    }
}
