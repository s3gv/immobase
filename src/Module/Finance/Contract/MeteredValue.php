<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;

/**
 * Was fuer eine Einheit erfasst wurde.
 *
 * Zwei Erfassungsarten, ein Datensatz: entweder steht hier nur der Verbrauch
 * und wir verteilen den Gesamtbetrag danach, oder es steht auch ein Betrag
 * dabei — dann hat der Dienstleister bereits verteilt und wir uebernehmen
 * seine Zahl. Heizkosten rechnen wir nie selbst.
 */
final readonly class MeteredValue
{
    public function __construct(
        public string $consumption,
        public ?Money $amount,
    ) {
    }
}
