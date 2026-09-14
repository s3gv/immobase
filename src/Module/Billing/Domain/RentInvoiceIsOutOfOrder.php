<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Die Kette der Fassungen hat eine Reihenfolge.
 *
 * Jeder Tag gehoert genau einer Fassung. Das haelt nur, solange jede Fassung
 * unmittelbar auf die zuletzt ausgestellte folgt und spaeter beginnt als
 * diese: eine uebersprungene Fassung schliesst nichts mehr, weil die
 * uebernaechste schon die juengste ist, und eine rueckdatierte schliesst die
 * vorige auf einen Tag, an dem sie selbst schon galt.
 *
 * Beides endet gleich — zwei offene Rechnungen ueber denselben Zeitraum,
 * beide beim Mieter, beide mit eigener Nummer.
 */
final class RentInvoiceIsOutOfOrder extends RuntimeException
{
    public static function aVersionIsMissing(): self
    {
        return new self('billing.error.invoice_skips_a_version');
    }

    public static function itDoesNotBeginLater(): self
    {
        return new self('billing.error.invoice_begins_too_early');
    }

    public static function theCorrectedVersionIsOutdated(): self
    {
        return new self('billing.error.invoice_corrects_an_outdated_version');
    }
}
