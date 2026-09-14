<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\DistributionDirectory;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\DistributionKeyShare;

/**
 * Die festen Anteile eines Verteilerschluessels, fuer die Abrechnung.
 */
final readonly class SurveyDistributionForBilling implements DistributionDirectory
{
    public function __construct(private DistributionKeyRepository $keys)
    {
    }

    public function sharesOf(string $keyId): array
    {
        $key = $this->keys->byId($keyId);

        if (null === $key) {
            return [];
        }

        $shares = [];

        foreach ($key->shares() as $share) {
            $shares[$share->unitId()] = self::amount($share);
        }

        return $shares;
    }

    private static function amount(DistributionKeyShare $share): string
    {
        return $share->share();
    }
}
