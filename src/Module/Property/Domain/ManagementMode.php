<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Wie ein Objekt verwaltet wird.
 *
 * Mehreres zugleich ist der Normalfall: eine Eigentuemergemeinschaft, in der
 * einzelne Wohnungen zusaetzlich in Sondereigentumsverwaltung stehen, traegt
 * WEG und SEV.
 *
 * Nur am Objekt und nicht an der Einheit — ein Feld, eine Wahrheit.
 */
enum ManagementMode: string
{
    case Weg = 'weg';
    case Sev = 'sev';
    case Rental = 'rental';

    public function labelKey(): string
    {
        return 'property.mode.'.$this->value;
    }

    /** Kennt diese Verwaltungsart Miteigentumsanteile? */
    public function needsMea(): bool
    {
        return self::Rental !== $this;
    }
}
