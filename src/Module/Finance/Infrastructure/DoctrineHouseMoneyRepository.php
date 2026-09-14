<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure;

use App\Module\Finance\Domain\AdvanceSchedule;
use App\Module\Finance\Domain\HouseMoney;
use App\Module\Finance\Domain\HouseMoneyRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineHouseMoneyRepository implements HouseMoneyRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(HouseMoney $step): void
    {
        $this->entityManager->persist($step);
        $this->entityManager->flush();
    }

    /**
     * Ein Flush fuer alle und nicht einer je Stufe: Doctrine schliesst den
     * Manager, sobald einer scheitert, und dann ist auch der Rest verloren.
     */
    public function saveAll(array $steps): void
    {
        foreach ($steps as $step) {
            $this->entityManager->persist($step);
        }

        $this->entityManager->flush();
    }

    public function remove(HouseMoney $step): void
    {
        $this->entityManager->remove($step);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?HouseMoney
    {
        return $this->entityManager->getRepository(HouseMoney::class)->find($id);
    }

    public function forUnits(array $unitIds): array
    {
        if ([] === $unitIds) {
            return [];
        }

        /** @var list<HouseMoney> $steps */
        $steps = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(HouseMoney::class, 'h')
            ->where('h.unitId IN (:units)')
            ->setParameter('units', $unitIds, ArrayParameterType::STRING)
            ->orderBy('h.startsOn', 'ASC')
            ->getQuery()
            ->getResult();

        $byUnit = [];

        foreach ($steps as $step) {
            $byUnit[$step->unitId()][] = $step;
        }

        return array_map(AdvanceSchedule::of(...), $byUnit);
    }
}
