<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/** Womit geheizt wird. */
enum HeatingType: string
{
    case Gas = 'gas';
    case Oil = 'oil';
    case DistrictHeating = 'district';
    case HeatPump = 'heat_pump';
    case Pellet = 'pellet';
    case Electricity = 'electricity';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'property.heating.type.'.$this->value;
    }
}
