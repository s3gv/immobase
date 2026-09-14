<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\PlanRepository;
use App\Module\Finance\Contract\PlanSources;

/**
 * Meldet den Finanzen, was in einem Wirtschaftsplan steckt.
 */
final readonly class HeldPlanSources implements PlanSources
{
    public function __construct(private PlanRepository $plans)
    {
    }

    public function usedByAPlan(array $sourceIds): array
    {
        return [] === $sourceIds ? [] : $this->plans->holders($sourceIds);
    }
}
