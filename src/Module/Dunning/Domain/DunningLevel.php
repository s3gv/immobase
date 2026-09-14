<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

/**
 * Die Stufen des Mahnwesens.
 *
 * Drei, und danach das Gericht. Die naechste Stufe folgt auf die vorige;
 * uebersprungen wird keine — eine letzte Mahnung, der keine erste
 * vorausging, ist keine letzte.
 *
 * **Die Zahlungserinnerung traegt nie Mahnkosten.** Das ist keine
 * Einstellung, sondern die Umsetzung von BGH VIII ZR 95/18: Mahnkosten sind
 * Verzugsschaden, und das erste Schreiben loest den Verzug erst aus.
 */
enum DunningLevel: string
{
    case Reminder = 'reminder';
    case First = 'first';
    case Final = 'final';

    public function labelKey(): string
    {
        return 'dunning.level.'.$this->value;
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Reminder => self::First,
            self::First => self::Final,
            self::Final => null,
        };
    }

    public function follows(?self $previous): bool
    {
        return $this === (null === $previous ? self::Reminder : $previous->next());
    }

    /** Darf dieses Schreiben Mahnkosten tragen? */
    public function mayCharge(): bool
    {
        return self::Reminder !== $this;
    }

    public function isLast(): bool
    {
        return self::Final === $this;
    }
}
