<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Infrastructure;

use App\Module\Dunning\Domain\BaseRate;
use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\BaseRates;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBaseRateRepository implements BaseRateRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(BaseRate $rate): void
    {
        $this->entityManager->persist($rate);
        $this->entityManager->flush();
    }

    public function remove(BaseRate $rate): void
    {
        $this->entityManager->remove($rate);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?BaseRate
    {
        return $this->entityManager->getRepository(BaseRate::class)->find($id);
    }

    public function all(): BaseRates
    {
        /** @var list<BaseRate> $rates */
        $rates = $this->entityManager->getRepository(BaseRate::class)->findBy([], ['validFrom' => 'ASC']);

        return BaseRates::of($rates);
    }
}
