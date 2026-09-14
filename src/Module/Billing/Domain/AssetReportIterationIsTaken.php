<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Zu diesem Bericht ist schon eine berichtigte Fassung unterwegs.
 *
 * Zwei offene Fassungen desselben Berichts waeren zwei Auskuenfte ueber
 * dasselbe Jahr, und der Empfaenger muesste raten, welche gilt.
 */
final class AssetReportIterationIsTaken extends RuntimeException
{
    public static function already(): self
    {
        return new self('billing.report.error.iteration_taken');
    }
}
