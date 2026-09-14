<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Infrastructure;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimFilter;
use App\Module\Dunning\Domain\ClaimOrigin;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Shared\Search\LikePattern;
use App\Shared\Ui\Page;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class DoctrineClaimRepository implements ClaimRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Claim $claim): void
    {
        $this->entityManager->persist($claim);
        $this->entityManager->flush();
    }

    public function remove(Claim $claim): void
    {
        $this->entityManager->remove($claim);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?Claim
    {
        return $this->entityManager->getRepository(Claim::class)->find($id);
    }

    public function forAdvance(string $paymentId): ?Claim
    {
        /** @var Claim|null $claim */
        $claim = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Claim::class, 'c')
            ->where('c.source.origin = :origin')
            ->andWhere('c.source.originId = :payment')
            ->setParameter('origin', ClaimOrigin::Advance)
            ->setParameter('payment', $paymentId)
            ->getQuery()
            ->getOneOrNullResult();

        return $claim;
    }

    public function advancesWithAClaim(): array
    {
        /** @var list<array{originId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.source.originId AS originId')
            ->from(Claim::class, 'c')
            ->where('c.source.origin = :origin')
            ->setParameter('origin', ClaimOrigin::Advance)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['originId'], $rows);
    }

    public function openFor(string $debtorPartyId, CreditorIdentity $creditor): array
    {
        /** @var list<Claim> $claims */
        $claims = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Claim::class, 'c')
            ->where('c.debtor.partyId = :debtor')
            ->andWhere('c.source.creditor = :creditor')
            ->andWhere('c.source.propertyId = :property')
            ->andWhere('c.source.creditorPartyIds = :owners')
            ->andWhere('c.arrears.settledOn IS NULL')
            ->setParameter('debtor', $debtorPartyId)
            ->setParameter('creditor', $creditor->role)
            ->setParameter('property', $creditor->propertyId)
            ->setParameter('owners', $creditor->partyIds)
            ->orderBy('c.arrears.dueOn', 'ASC')
            ->getQuery()
            ->getResult();

        return $claims;
    }

    public function allOpen(): array
    {
        /** @var list<Claim> $claims */
        $claims = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Claim::class, 'c')
            ->where('c.arrears.settledOn IS NULL')
            ->orderBy('c.arrears.defaultFrom', 'ASC')
            ->getQuery()
            ->getResult();

        return $claims;
    }

    public function countMatching(ClaimFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(c.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(ClaimFilter $filter, Page $page): array
    {
        // Der aelteste Verzug zuerst: wer die Liste oeffnet, sucht den Fall,
        // der am laengsten liegt, und nicht den von gestern.
        /** @var list<Claim> $claims */
        $claims = $this->restricted($filter, 'c')
            ->orderBy('c.arrears.settledOn', 'ASC')
            ->addOrderBy('c.arrears.defaultFrom', 'ASC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $claims;
    }

    /**
     * Der Zustand wird **nicht** hier eingeschraenkt.
     *
     * „Frist abgelaufen" haengt daran, welches Schreiben zuletzt hinausging
     * und welche Frist in den Einstellungen steht — beides steht nicht in
     * dieser Tabelle. Es waere ein Filter, der in der Abfrage nur halb
     * stimmte; er sitzt darum in der Anwendungsschicht.
     */
    private function restricted(ClaimFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(Claim::class, 'c');

        if (null !== $filter->propertyId) {
            $query->andWhere('c.source.propertyId = :property')->setParameter('property', $filter->propertyId);
        }

        if (ClaimFilter::SETTLED === $filter->state) {
            $query->andWhere('c.arrears.settledOn IS NOT NULL');
        } elseif (null !== $filter->state) {
            $query->andWhere('c.arrears.settledOn IS NULL');
        }

        if (null !== $filter->search) {
            $query->andWhere('LOWER(c.subject) LIKE :term')
                ->setParameter('term', LikePattern::containing(mb_strtolower($filter->search)));
        }

        return $query;
    }
}
