<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Ueber ein Jahr, das noch laeuft, gibt es keinen Vermoegensbericht.
 *
 * § 28 Abs. 4 WEG sagt „nach Ablauf des Kalenderjahres", und das ist keine
 * Foermelei: der Bericht spricht ueber einen Stichtag. Liegt der in der
 * Zukunft, stuende darin, was am 31. Dezember auf dem Konto sein wird — und
 * das weiss niemand.
 */
final class ReportYearIsNotOver extends RuntimeException
{
    public static function itIsStillRunning(): self
    {
        return new self('billing.report.error.year_not_over');
    }
}
