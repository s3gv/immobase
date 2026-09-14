<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Zu diesem Budgetplan ist schon eine berichtigte Fassung unterwegs.
 *
 * Zwei offene Fassungen desselben Vorgangs waeren zwei Beschluesse ueber
 * dieselbe Massnahme, und der Empfaenger muesste raten, welcher gilt.
 */
final class BudgetIterationIsTaken extends RuntimeException
{
    public static function already(): self
    {
        return new self('billing.budget.error.iteration_taken');
    }
}
