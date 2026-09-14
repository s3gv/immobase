<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Ob wir diese Einheit noch verwalten.
 *
 * Kein „Entwurf": Einheiten entstehen im Objektablauf und sind mit dem
 * Anlegen fertig. Beendet heisst verkauft, abgegeben, nicht mehr unsere — bei
 * Sondereigentumsverwaltung ist das der Normalfall, dort verwaltet man
 * einzelne Einheiten und verliert sie einzeln.
 *
 * Was einmal verwaltet wurde, wird nicht geloescht: die Abrechnung des
 * vergangenen Jahres braucht die Einheit noch.
 */
enum UnitStatus: string
{
    case Active = 'active';
    case Ended = 'ended';

    public function labelKey(): string
    {
        return 'property.unit.status.'.$this->value;
    }

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    /** Vergangenes — wird in Listen nur auf Wunsch gezeigt. */
    public function isPast(): bool
    {
        return self::Ended === $this;
    }
}
