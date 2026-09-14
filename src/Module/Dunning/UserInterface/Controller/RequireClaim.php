<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Forderung zur Adresse — oder 404.
 *
 * An einer Stelle, damit nicht jeder Controller dieselbe Zeile schreibt und
 * einer davon sie vergisst.
 */
final readonly class RequireClaim
{
    public function __construct(private ClaimRepository $claims)
    {
    }

    public function __invoke(string $id): Claim
    {
        return $this->claims->byId($id) ?? throw new NotFoundHttpException('Diese Forderung gibt es nicht.');
    }
}
