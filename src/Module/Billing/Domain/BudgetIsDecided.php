<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Ein beschlossener Budgetplan aendert sich nicht mehr.
 *
 * Er ist zugestellt worden, und auf ihm stehen Forderungen: die Sonderumlage
 * ist faellig, das Ansparen laeuft. Was daran falsch ist, wird in einer
 * berichtigten Fassung richtiggestellt.
 */
final class BudgetIsDecided extends RuntimeException
{
    public static function already(): self
    {
        return new self('billing.budget.error.decided');
    }
}
