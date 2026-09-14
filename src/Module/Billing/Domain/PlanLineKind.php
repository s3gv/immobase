<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Was eine Zeile des Einzelwirtschaftsplans ist.
 *
 * Zwei Sorten, und der Unterschied ist keiner der Darstellung: ueber die
 * Vorschuesse und ueber die Zufuehrung zur Erhaltungsruecklage wird nach
 * § 28 Abs. 1 WEG **getrennt** beschlossen. Eine Ruecklagenzeile, die
 * zwischen Muellabfuhr und Versicherung stuende, verschwiege das.
 *
 * Gerechnet werden beide gleich — ein Betrag, ein Schluessel, ein Anteil.
 * Darum sind es Zeilen derselben Tabelle und nicht zwei Tabellen.
 */
enum PlanLineKind: string
{
    case Cost = 'cost';
    case Reserve = 'reserve';

    public function labelKey(): string
    {
        return 'billing.plan.line_kind.'.$this->value;
    }

    public function isReserve(): bool
    {
        return self::Reserve === $this;
    }
}
