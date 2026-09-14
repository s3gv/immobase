<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Infrastructure;

use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Module\Tenancy\Domain\Tenant;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineTenancyRepository implements TenancyRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenancyQueries $queries,
    ) {
    }

    public function save(Tenancy $tenancy): void
    {
        $this->entityManager->persist($tenancy);
        $this->entityManager->flush();
    }

    public function remove(Tenancy $tenancy): void
    {
        $this->entityManager->remove($tenancy);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?Tenancy
    {
        return $this->entityManager->getRepository(Tenancy::class)->find($id);
    }

    public function byNumber(int $number): ?Tenancy
    {
        return $this->entityManager->getRepository(Tenancy::class)->findOneBy(['number' => $number]);
    }

    public function nextNumber(): int
    {
        $next = $this->entityManager->getConnection()->fetchOne("SELECT nextval('tenancy_number_seq')");

        if (!is_numeric($next)) {
            throw new RuntimeException('Die Sequenz tenancy_number_seq hat keine Nummer geliefert.');
        }

        return (int) $next;
    }

    public function countMatching(TenancyFilter $filter): int
    {
        $count = $this->queries->restricted($filter, 'COUNT(t.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Tenancy> $found */
        $found = $this->queries->withIds($ids)->getQuery()->getResult();

        return $found;
    }

    public function countEndingBy(DateTimeImmutable $day): int
    {
        $count = $this->queries->endingBy($day)->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(TenancyFilter $filter, Page $page, Sort $sort): array
    {
        /** @var list<Tenancy> $tenancies */
        $tenancies = $this->queries
            ->sorted($this->queries->restricted($filter, 't'), $sort)
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $tenancies;
    }

    public function activeFor(string $unitId, ?string $exceptTenancyId = null): ?Tenancy
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Tenancy::class, 't')
            ->where('t.unitId = :unit')
            ->andWhere('t.status = :active')
            ->setParameter('unit', $unitId)
            ->setParameter('active', TenancyStatus::Active->value)
            ->setMaxResults(1);

        if (null !== $exceptTenancyId) {
            $query->andWhere('t.id <> :except')->setParameter('except', $exceptTenancyId);
        }

        $found = $query->getQuery()->getOneOrNullResult();

        return $found instanceof Tenancy ? $found : null;
    }

    public function overlapping(
        string $unitId,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?string $exceptTenancyId = null,
    ): ?Tenancy {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Tenancy::class, 't')
            ->where('t.unitId = :unit')
            ->andWhere('t.status <> :draft')
            ->setParameter('unit', $unitId)
            ->setParameter('draft', TenancyStatus::Draft->value)
            ->setMaxResults(1);

        $this->queries->onlyWithin($query, $from, $to);

        if (null !== $exceptTenancyId) {
            $query->andWhere('t.id <> :except')->setParameter('except', $exceptTenancyId);
        }

        $found = $query->getQuery()->getOneOrNullResult();

        return $found instanceof Tenancy ? $found : null;
    }

    public function forUnits(array $unitIds): array
    {
        if ([] === $unitIds) {
            return [];
        }

        /** @var list<Tenancy> $tenancies */
        $tenancies = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Tenancy::class, 't')
            ->where('t.unitId IN (:units)')
            ->setParameter('units', $unitIds, ArrayParameterType::STRING)
            // Aktive zuerst: auf der Einheitenseite steht oben, was gilt.
            // Ueber einen Rang und nicht ueber die Spalte — alphabetisch
            // stuende „ended" vor „active".
            ->addSelect('CASE WHEN t.status = :active THEN 0 ELSE 1 END AS HIDDEN rank')
            ->setParameter('active', TenancyStatus::Active->value)
            ->orderBy('rank', 'ASC')
            ->addOrderBy('t.number', 'DESC')
            ->getQuery()
            ->getResult();

        $found = [];

        foreach ($tenancies as $tenancy) {
            $found[$tenancy->unitId()][] = $tenancy;
        }

        return $found;
    }

    public function rentedBy(string $partyId): array
    {
        /** @var list<Tenancy> $tenancies */
        $tenancies = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Tenancy::class, 't')
            ->join('t.tenants', 'm')
            ->where('m.partyId = :party')
            ->andWhere('t.status <> :draft')
            ->setParameter('party', $partyId)
            ->setParameter('draft', TenancyStatus::Draft->value)
            ->orderBy('t.number', 'DESC')
            ->getQuery()
            ->getResult();

        return $tenancies;
    }

    public function rentingParties(array $partyIds): array
    {
        if ([] === $partyIds) {
            return [];
        }

        /** @var list<array{partyId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT m.partyId AS partyId')
            ->from(Tenant::class, 'm')
            ->where('m.partyId IN (:parties)')
            ->setParameter('parties', $partyIds, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        return array_values(array_map(static fn (array $row): string => $row['partyId'], $rows));
    }
}
