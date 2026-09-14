<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Wozu eine Einheit da ist.
 *
 * Entscheidet zweierlei: wie das Flaechenfeld heisst — Wohnflaeche oder
 * Nutzflaeche — und spaeter, ob eine Rechnung Umsatzsteuer ausweist. Bei
 * Wohnraum ist die Vermietung nach § 4 Nr. 12 UStG steuerfrei, bei Gewerbe
 * nicht.
 *
 * „Stellplatz" steht dabei, weil ein Objekt auch eine Reihe Garagen sein kann.
 */
enum UnitUsage: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case Parking = 'parking';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'property.usage.'.$this->value;
    }

    /** Die Beschriftung des Flaechenfeldes haengt an der Nutzung. */
    public function areaLabelKey(): string
    {
        return self::Residential === $this
            ? 'property.unit.field.living_area'
            : 'property.unit.field.usable_area';
    }
}
