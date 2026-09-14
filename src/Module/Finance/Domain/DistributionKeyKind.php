<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Woher die Anteile eines Verteilerschluessels kommen.
 *
 * Drei Sorten, und die Sorte sagt alles Weitere:
 *
 * * **Berechnet** — die Werte stehen schon im System: Flaechen und
 *   Miteigentumsanteile an der Einheit, die Personenzahl am
 *   Mietverhaeltnis, „nach Einheiten" zu gleichen Teilen.
 * * **Erfasst** — der Verbrauch kommt von aussen und wird an der
 *   Kostenposition eingetragen, je Einheit und je Wirtschaftsjahr.
 * * **Fest** — Anteile, die jemand einmal festlegt. Deckt auch „trifft nur
 *   WE 3" ab: ein Anteil von 100 %. Ein eigener Typ dafuer waere ein
 *   Sonderfall, den niemand braucht.
 */
enum DistributionKeyKind: string
{
    case Area = 'area';
    case Mea = 'mea';
    case Persons = 'persons';
    case Units = 'units';
    case Metered = 'metered';
    case Fixed = 'fixed';

    public function labelKey(): string
    {
        return 'finance.key.kind.'.$this->value;
    }

    public function explanationKey(): string
    {
        return 'finance.key.kind_explanation.'.$this->value;
    }

    /** Die Werte stehen schon im System — nichts zu erfassen. */
    public function isComputed(): bool
    {
        return \in_array($this, [self::Area, self::Mea, self::Persons, self::Units], true);
    }

    /** Der Verbrauch wird an der Kostenposition erfasst. */
    public function isMetered(): bool
    {
        return self::Metered === $this;
    }

    /** Die Anteile haengen am Schluessel selbst. */
    public function needsShares(): bool
    {
        return self::Fixed === $this;
    }
}
