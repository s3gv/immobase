<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

/**
 * Wer fordert.
 *
 * Hausgeld und Sonderumlage schuldet der Eigentuemer der **Gemeinschaft**,
 * die Nebenkostenvorauszahlung schuldet der Mieter seinem **Vermieter**.
 * Beides in einem Brief waere eine Forderung aus zwei Haenden.
 *
 * Darum wird nicht nur nach Schuldner gruppiert, sondern nach Schuldner und
 * Glaeubiger. In der Praxis trennt das sauber, weil Mieter und Eigentuemer
 * verschiedene Personen sind — ausser beim selbstnutzenden Eigentuemer, und
 * genau dort waere der gemeinsame Brief falsch.
 */
enum Creditor: string
{
    case Community = 'community';
    case Owner = 'owner';

    public function labelKey(): string
    {
        // `dunning.creditor` ist schon die Spaltenueberschrift — der
        // Schluessel der Auspraegungen braucht darum einen eigenen Zweig.
        return 'dunning.creditor_kind.'.$this->value;
    }
}
