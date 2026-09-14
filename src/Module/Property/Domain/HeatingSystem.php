<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Eine Anlage fuer das Haus oder eine je Wohnung.
 *
 * Entscheidet, ob es ueberhaupt etwas zu verteilen gibt: bei dezentralen
 * Anlagen zahlt jeder seine Waerme selbst, und die Heizkostenabrechnung
 * entfaellt.
 */
enum HeatingSystem: string
{
    case Central = 'central';
    case Decentral = 'decentral';

    public function labelKey(): string
    {
        return 'property.heating.system.'.$this->value;
    }
}
