<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

/**
 * Woher eine Forderung kommt.
 *
 * Selbsttaetig gemahnt wird nur, was die Anwendung auch weiss — die
 * Vorauszahlungen. Alles andere traegt jemand ein, und **damit steht auch der
 * Mahnstart fest**, denn der Verzugsbeginn kommt mit.
 *
 * Der Unterschied ist nicht nur Herkunft: bei einer Vorauszahlung wird der
 * offene Betrag vor jedem Schreiben neu aus den Finanzen gelesen, bei einer
 * eingetragenen Forderung steht er, wo ihn jemand hingeschrieben hat.
 */
enum ClaimOrigin: string
{
    case Advance = 'advance';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return 'dunning.origin.'.$this->value;
    }
}
