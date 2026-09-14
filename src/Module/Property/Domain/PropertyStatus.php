<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Wo ein Objekt in seinem Leben steht.
 *
 * „Entwurf" ist kein Zwischenspeicher, sondern ein Datensatz: der erste
 * Schritt legt an, jeder weitere speichert sofort, der letzte setzt „Aktiv".
 * So laesst sich an einem anderen Tag und an einem anderen Rechner
 * weitermachen — ein Objekt ist zu gross, um es in einem Zug einzugeben.
 *
 * „Beendet" ist der Verwaltervertrag, der ausgelaufen ist. Das Objekt
 * verschwindet dabei nicht: die Abrechnung des laufenden Jahres steht noch
 * aus, und die Geschichte bleibt lesbar. Geloescht wird nur, was nie
 * gelaufen ist — also hoechstens ein Entwurf.
 */
enum PropertyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Ended = 'ended';

    public function labelKey(): string
    {
        return 'property.status.'.$this->value;
    }

    public function isDraft(): bool
    {
        return self::Draft === $this;
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
