<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Es fehlt etwas, ohne das der Bericht nicht herausgehen darf.
 *
 * Ein Konto ohne Stand waere eine Null, die niemand eingegeben hat, und ein
 * Bericht ohne Empfaenger ein Schreiben ohne Anschrift. Was fehlt, wird nicht
 * still zu null.
 */
final class AssetReportIsIncomplete extends RuntimeException
{
    public static function amountsAreMissing(): self
    {
        return new self('billing.report.error.amounts_missing');
    }

    public static function thereIsNoOneToSendTo(): self
    {
        return new self('billing.report.error.no_recipients');
    }
}
