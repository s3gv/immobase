<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Woher das warme Wasser kommt.
 *
 * „Verbunden" heisst: dieselbe Anlage macht Waerme und Warmwasser. Dann
 * verlangt § 9 Heizkostenverordnung, die Kosten vor dem Verteilen zwischen
 * beidem aufzuteilen. Ohne diese Angabe laesst sich die Heizkostenabrechnung
 * spaeter nicht rechnen — deshalb steht sie hier und nicht erst dort.
 */
enum HotWater: string
{
    case Connected = 'connected';
    case Separate = 'separate';
    case Decentral = 'decentral';

    public function labelKey(): string
    {
        return 'property.heating.water.'.$this->value;
    }
}
