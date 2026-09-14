<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\StatementRepository;
use App\Module\Finance\Contract\StatementSources;

/**
 * Meldet den Finanzen, was in einer Abrechnung steckt.
 */
final readonly class HeldSources implements StatementSources
{
    public function __construct(private StatementRepository $statements)
    {
    }

    public function usedByAStatement(array $sourceIds): array
    {
        return [] === $sourceIds ? [] : $this->statements->holders($sourceIds);
    }
}
