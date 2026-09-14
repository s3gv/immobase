<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Wie ein Verbrauch erfasst wurde.
 *
 * Die Dienstleister liefern unterschiedlich, und das laesst sich nicht
 * vereinheitlichen:
 *
 * * **Gesamtbetrag** — eine Rechnungssumme und die Verbrauchswerte je
 *   Einheit. Die Verteilung rechnen wir.
 * * **Fertig verteilt** — Verbrauch *und* Betrag je Einheit kommen fertig.
 *   Der Gesamtbetrag ist dann die Summe der Teile, nicht die Rechnungssumme.
 *
 * Heizkosten sind der Normalfall der zweiten Art: nach der HeizkostenV
 * duerfen sie nicht rein nach Verbrauch verteilt werden, und die
 * Abrechnungsdienste liefern die vorgeschriebene Aufteilung fertig. Wir
 * erfassen den Betrag, den sie senden — gerechnet wird er hier nie.
 */
enum EntryMode: string
{
    case Total = 'total';
    case Distributed = 'distributed';

    public function labelKey(): string
    {
        return 'finance.year.mode.'.$this->value;
    }

    public function hintKey(): string
    {
        return 'finance.year.mode_hint.'.$this->value;
    }

    /** Der Dienstleister hat verteilt — die Betraege je Einheit stehen fest. */
    public function isDistributed(): bool
    {
        return self::Distributed === $this;
    }
}
