<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Berichtigt wird ein Beschluss, der gefasst wurde.
 *
 * Dieselben zwei Faelle wie beim Vermoegensbericht: ein Entwurf wird
 * bearbeitet, und eine ueberholte Fassung wird nicht noch einmal berichtigt.
 */
final class BudgetCannotBeCorrected extends RuntimeException
{
    public static function itIsStillADraft(): self
    {
        return new self('billing.budget.error.draft_not_corrected');
    }

    public static function itIsNotTheLatestVersion(): self
    {
        return new self('billing.budget.error.outdated');
    }
}
