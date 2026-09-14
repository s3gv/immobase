<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\CostCatalogue;
use App\Module\Finance\Contract\CostKindBrief;
use App\Module\Finance\Contract\DistributionKeyBrief;
use App\Module\Finance\Contract\LoanCostKinds;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyRepository;

/**
 * Die Katalogfrage der Planung beantworten.
 */
final readonly class SurveyCatalogueForBilling implements CostCatalogue
{
    /** Die Namen stehen so in der Migration, die die beiden Arten mitliefert. */
    private const string INTEREST = 'Darlehenszinsen';
    private const string PRINCIPAL = 'Darlehenstilgung';

    public function __construct(
        private CostKindRepository $kinds,
        private DistributionKeyRepository $keys,
    ) {
    }

    public function kinds(): array
    {
        return array_map(
            static fn (CostKind $kind): CostKindBrief => new CostKindBrief(
                $kind->id(),
                $kind->name(),
                $kind->isApportionable(),
            ),
            $this->kinds->all(),
        );
    }

    public function loanKinds(): LoanCostKinds
    {
        $found = [];

        foreach ($this->kinds->all() as $kind) {
            $found[$kind->name()] = new CostKindBrief($kind->id(), $kind->name(), $kind->isApportionable());
        }

        return new LoanCostKinds($found[self::INTEREST] ?? null, $found[self::PRINCIPAL] ?? null);
    }

    public function keysFor(string $propertyId): array
    {
        return array_map(
            static fn (DistributionKey $key): DistributionKeyBrief => new DistributionKeyBrief(
                $key->id(),
                $key->name(),
                $key->kind()->value,
            ),
            $this->keys->forProperty($propertyId),
        );
    }
}
