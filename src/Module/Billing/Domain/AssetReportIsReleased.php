<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Ein herausgegebener Vermoegensbericht aendert sich nicht mehr.
 *
 * Er ist zugestellt worden. Was daran falsch ist, wird in einer berichtigten
 * Fassung richtiggestellt — das Gesetz nennt den Anlass selbst, den
 * Berichtigungsanspruch des Eigentuemers (§ 28 Abs. 4 WEG).
 */
final class AssetReportIsReleased extends RuntimeException
{
    public static function already(): self
    {
        return new self('billing.report.error.released');
    }
}
