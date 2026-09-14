<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure;

use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDistributionKeyRepository implements DistributionKeyRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(DistributionKey $key): void
    {
        $this->entityManager->persist($key);
        $this->entityManager->flush();
    }

    public function remove(DistributionKey $key): void
    {
        $this->entityManager->remove($key);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?DistributionKey
    {
        return $this->entityManager->getRepository(DistributionKey::class)->find($id);
    }

    public function forProperty(?string $propertyId): array
    {
        /** @var list<DistributionKey> $keys */
        $keys = $this->entityManager->createQueryBuilder()
            ->select('k')
            ->from(DistributionKey::class, 'k')
            ->where('k.propertyId IS NULL')
            ->orWhere('k.propertyId = :property')
            ->setParameter('property', $propertyId)
            // Die Systemschluessel zuerst: sie sind die haeufige Wahl.
            ->orderBy('k.system', 'DESC')
            ->addOrderBy('k.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $keys;
    }

    public function all(): array
    {
        /** @var list<DistributionKey> $keys */
        $keys = $this->entityManager->getRepository(DistributionKey::class)
            ->findBy([], ['system' => 'DESC', 'name' => 'ASC']);

        return $keys;
    }
}
