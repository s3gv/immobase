<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Wie oft etwas faellig wird.
 *
 * „Einmalig" ist keine Wiederholung, sondern ihr Gegenteil: die
 * Dachreparatur in diesem Jahr. Sie steht trotzdem hier, weil die Frage
 * dieselbe ist — wann faellt es an.
 *
 * Im Vertrag und nicht im Inneren der Finanzen, weil andere Module dieselbe
 * Frage stellen: der Wirtschaftsplan beschliesst Vorschuesse mit einem
 * Zahlungsintervall und schreibt sie als Hausgeldstufe fort. Zwei Aufzaehlungen
 * fuer dasselbe Wort waeren zwei Wahrheiten darueber, was „monatlich" heisst.
 */
enum Interval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annually = 'annually';
    case Once = 'once';

    public function labelKey(): string
    {
        return 'finance.interval.'.$this->value;
    }

    /** Nur jaehrliche und einmalige brauchen einen Monat. */
    public function needsAMonth(): bool
    {
        return \in_array($this, [self::Annually, self::Once], true);
    }

    /** Wie oft im Jahr — fuer die spaetere Hochrechnung. */
    public function timesAYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Quarterly => 4,
            self::Annually, self::Once => 1,
        };
    }
}
