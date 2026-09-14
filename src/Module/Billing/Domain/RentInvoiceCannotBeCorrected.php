<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Berichtigt wird nur, was ausgestellt ist — und nur die juengste Fassung.
 *
 * Ein Entwurf wird geaendert, nicht berichtigt: er hat nie gegolten. Und wer
 * eine ueberholte Fassung berichtigte, schriebe eine Rechnung fort, die
 * laengst von einer Folgefassung abgeloest ist.
 */
final class RentInvoiceCannotBeCorrected extends RuntimeException
{
    public static function itIsADraft(): self
    {
        return new self('billing.error.invoice_draft_correction');
    }

    public static function itIsOutdated(): self
    {
        return new self('billing.error.invoice_outdated');
    }
}
