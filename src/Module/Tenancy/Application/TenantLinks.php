<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Party\Contract\PartyLinkSource;
use App\Module\Tenancy\Domain\TenancyRepository;

/**
 * Meldet den Stammdaten, wer mietet.
 *
 * Die zweite Umsetzung von PartyLinkSource neben dem Eigentum. Wer Mieter
 * ist, laesst sich nicht loeschen — auch nicht, wenn das Mietverhaeltnis
 * laengst inaktiv ist: dann steht der Name in der Geschichte einer Einheit,
 * und eine Geschichte mit einer Luecke ist keine.
 */
final readonly class TenantLinks implements PartyLinkSource
{
    public function __construct(private TenancyRepository $tenancies)
    {
    }

    public function linksTo(array $partyIds): array
    {
        $links = [];

        foreach ($this->tenancies->rentingParties($partyIds) as $partyId) {
            $links[$partyId] = ['tenancy.tenant.link'];
        }

        return $links;
    }
}
