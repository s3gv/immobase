<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * Was ab einem Monat anders ist — eine Sondertilgung, ein neuer Zins.
 *
 * Der Plan bleibt eine Rechnung und wird kein Datensatz: er wird jedes Mal
 * neu gerechnet, und diese Aenderungen gehen mit hinein. Ein gespeicherter
 * Plan mit nachgetragenen Zeilen waere die zweite Wahrheit neben der
 * Rechnung, und spaetestens die dritte Sondertilgung liefe von ihr weg.
 *
 * **Der Monat und kein Datum.** Hier wird gerechnet und nicht kalendert; wer
 * Termine hat, rechnet sie in Monate um. Gezaehlt wird ab eins: `afterMonth`
 * 12 heisst „nach der zwoelften Rate", die Aenderung wirkt also ab der
 * dreizehnten.
 */
final readonly class PlanChange
{
    public function __construct(
        public int $afterMonth,
        /** Eine ausserplanmaessige Tilgung — sie mindert die Restschuld sofort. */
        public ?Money $extra = null,
        /** Der neue Zinssatz in Basispunkten; null heisst: der alte gilt weiter. */
        public ?int $rateBps = null,
    ) {
    }
}
