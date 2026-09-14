<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

/**
 * Wozu ein Stammdatensatz gehoert.
 *
 * Mehrere Rollen sind moeglich: wer eine Wohnung besitzt und eine andere
 * mietet, ist beides. Ein zweiter Datensatz dafuer haette eine zweite
 * Referenznummer, und genau die soll dem Zuordnen dienen.
 *
 * Lieferanten stehen bewusst nicht hier. Wer eine Rechnung gestellt hat,
 * gehoert an den Beleg, nicht in die Stammdaten.
 */
enum PartyRole: string
{
    case Tenant = 'tenant';
    case Owner = 'owner';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'party.role.'.$this->value;
    }
}
