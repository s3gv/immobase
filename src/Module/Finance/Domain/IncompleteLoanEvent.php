<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Zu diesem Vorgang fehlt die Zahl, die ihn ausmacht.
 *
 * Eine Sondertilgung ohne Betrag ist keine, und eine Zinsaenderung ohne Satz
 * auch nicht. Ein leeres Feld als Null zu lesen waere schlimmer als die
 * Absage: der Plan rechnete sich klaglos neu — mit null Prozent Zins.
 */
final class IncompleteLoanEvent extends RuntimeException
{
    public static function withoutAnAmount(): self
    {
        return new self('finance.error.loan_extra_missing');
    }

    public static function withoutARate(): self
    {
        return new self('finance.error.loan_rate_missing');
    }
}
