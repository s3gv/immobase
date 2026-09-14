<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Infrastructure;

use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeLine;
use App\Module\Dunning\Domain\NoticeRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineNoticeRepository implements NoticeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Notice $notice): void
    {
        $this->entityManager->persist($notice);
        $this->entityManager->flush();
    }

    public function remove(Notice $notice): void
    {
        $this->entityManager->remove($notice);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?Notice
    {
        return $this->entityManager->getRepository(Notice::class)->find($id);
    }

    public function atomically(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction($work);
    }

    public function nextNumberFor(string $debtorPartyId): int
    {
        $highest = $this->entityManager->createQueryBuilder()
            ->select('MAX(n.reference.number)')
            ->from(Notice::class, 'n')
            ->where('n.reference.partyId = :debtor')
            ->setParameter('debtor', $debtorPartyId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($highest) ? (int) $highest + 1 : 1;
    }

    public function forClaims(array $claimIds): array
    {
        if ([] === $claimIds) {
            return [];
        }

        /** @var list<Notice> $notices */
        $notices = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(Notice::class, 'n')
            ->innerJoin(NoticeLine::class, 'l', 'WITH', 'l.notice = n')
            ->where('l.claimId IN (:claims)')
            ->setParameter('claims', $claimIds)
            ->groupBy('n.id')
            // Nach der Nummer und nicht nach dem Anlegetag: die Nummer
            // zaehlt je Schuldner und ist damit die Reihenfolge der
            // Schreiben. Drei Schreiben, die in derselben Sekunde entstehen,
            // haben denselben Zeitstempel — und dann entschiede der Zufall,
            // welches als das juengste gilt.
            ->orderBy('n.reference.number', 'DESC')
            ->getQuery()
            ->getResult();

        return $notices;
    }

    public function lastIssuedFor(string $debtorPartyId, CreditorIdentity $creditor): ?Notice
    {
        /** @var Notice|null $notice */
        $notice = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(Notice::class, 'n')
            ->where('n.reference.partyId = :debtor')
            ->andWhere('n.reference.creditor = :creditor')
            ->andWhere('n.reference.propertyId = :property')
            ->andWhere('n.reference.creditorPartyIds = :owners')
            ->andWhere('n.issuedOn IS NOT NULL')
            ->setParameter('debtor', $debtorPartyId)
            ->setParameter('creditor', $creditor->role)
            ->setParameter('property', $creditor->propertyId)
            ->setParameter('owners', $creditor->partyIds)
            ->orderBy('n.issuedOn', 'DESC')
            ->addOrderBy('n.reference.number', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $notice;
    }

    public function openDraftFor(string $debtorPartyId, CreditorIdentity $creditor): ?Notice
    {
        /** @var Notice|null $notice */
        $notice = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(Notice::class, 'n')
            ->where('n.reference.partyId = :debtor')
            ->andWhere('n.reference.creditor = :creditor')
            ->andWhere('n.reference.propertyId = :property')
            ->andWhere('n.reference.creditorPartyIds = :owners')
            ->andWhere('n.issuedOn IS NULL')
            ->setParameter('debtor', $debtorPartyId)
            ->setParameter('creditor', $creditor->role)
            ->setParameter('property', $creditor->propertyId)
            ->setParameter('owners', $creditor->partyIds)
            ->orderBy('n.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $notice;
    }
}
