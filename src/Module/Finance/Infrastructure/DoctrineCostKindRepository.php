<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure;

use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCostKindRepository implements CostKindRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(CostKind $kind): void
    {
        $this->entityManager->persist($kind);
        $this->entityManager->flush();
    }

    public function remove(CostKind $kind): void
    {
        $this->entityManager->remove($kind);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?CostKind
    {
        return $this->entityManager->getRepository(CostKind::class)->find($id);
    }

    public function all(): array
    {
        /** @var list<CostKind> $kinds */
        $kinds = $this->entityManager->getRepository(CostKind::class)
            ->findBy([], ['ordering' => 'ASC', 'name' => 'ASC']);

        return $kinds;
    }
}
