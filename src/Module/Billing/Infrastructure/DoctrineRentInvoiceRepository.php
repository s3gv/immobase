<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceFilter;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Billing\Domain\StatementStatus;
use App\Shared\Search\LikePattern;
use App\Shared\Ui\Page;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class DoctrineRentInvoiceRepository implements RentInvoiceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(RentInvoice $invoice): void
    {
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }

    public function atomically(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction($work);
    }

    public function remove(RentInvoice $invoice): void
    {
        $this->entityManager->remove($invoice);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?RentInvoice
    {
        return $this->entityManager->getRepository(RentInvoice::class)->find($id);
    }

    public function nextNumberFor(string $tenancyId): int
    {
        $highest = $this->entityManager->createQueryBuilder()
            ->select('MAX(i.edition.number)')
            ->from(RentInvoice::class, 'i')
            ->where('i.tenancyId = :tenancy')
            ->setParameter('tenancy', $tenancyId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($highest) ? (int) $highest + 1 : 1;
    }

    public function countMatching(RentInvoiceFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(i.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(RentInvoiceFilter $filter, Page $page): array
    {
        // Die juengste Geltung zuerst: wer die Liste oeffnet, sucht die
        // Rechnung, die gerade gilt, und nicht die von vor drei Jahren. Und
        // bei gleicher Fassung die hoechste Iteration: die Berichtigung
        // loest das Schreiben ab, das sie berichtigt, und steht darum
        // darueber.
        /** @var list<RentInvoice> $invoices */
        $invoices = $this->restricted($filter, 'i')
            ->orderBy('i.validity.from', 'DESC')
            ->addOrderBy('i.tenancyNumber', 'DESC')
            ->addOrderBy('i.edition.number', 'DESC')
            ->addOrderBy('i.edition.iteration', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $invoices;
    }

    public function forTenancy(string $tenancyId): array
    {
        /** @var list<RentInvoice> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(RentInvoice::class, 'i')
            ->where('i.tenancyId = :tenancy')
            ->setParameter('tenancy', $tenancyId)
            ->orderBy('i.edition.number', 'ASC')
            ->addOrderBy('i.edition.iteration', 'ASC')
            ->getQuery()
            ->getResult();

        return $invoices;
    }

    public function lastIssuedFor(string $tenancyId): ?RentInvoice
    {
        /** @var RentInvoice|null $invoice */
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(RentInvoice::class, 'i')
            ->where('i.tenancyId = :tenancy')
            ->andWhere('i.release.status = :released')
            ->setParameter('tenancy', $tenancyId)
            ->setParameter('released', StatementStatus::Released)
            ->orderBy('i.edition.number', 'DESC')
            ->addOrderBy('i.edition.iteration', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $invoice;
    }

    public function correctedAmong(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Nur ausgestellte Berichtigungen zaehlen. Ein Entwurf liegt hier
        // und nicht beim Mieter; bis er hinausgeht, ist das Original die
        // gueltige Rechnung — und wuerde sonst als abgeloest dastehen,
        // waehrend nichts es abgeloest hat.
        /** @var list<array{correctsId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT i.edition.correctsId AS correctsId')
            ->from(RentInvoice::class, 'i')
            ->where('i.edition.correctsId IN (:ids)')
            ->andWhere('i.release.status = :released')
            ->setParameter('ids', $ids)
            ->setParameter('released', StatementStatus::Released)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['correctsId'], $rows);
    }

    public function tenanciesWithAnInvoice(): array
    {
        /** @var list<array{tenancyId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT i.tenancyId AS tenancyId')
            ->from(RentInvoice::class, 'i')
            ->where('i.release.status = :released')
            ->setParameter('released', StatementStatus::Released)
            ->getQuery()
            ->getResult();

        return array_column($rows, 'tenancyId');
    }

    private function restricted(RentInvoiceFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(RentInvoice::class, 'i');

        if (null !== $filter->propertyId) {
            $query->andWhere('i.propertyId = :property')->setParameter('property', $filter->propertyId);
        }

        if (null !== $filter->status) {
            $query->andWhere('i.release.status = :status')->setParameter('status', $filter->status);
        }

        if (null !== $filter->search) {
            self::restrictToSearch($query, $filter);
        }

        return $query;
    }

    private static function restrictToSearch(QueryBuilder $query, RentInvoiceFilter $filter): void
    {
        $conditions = ['LOWER(i.label) LIKE :needle', 'LOWER(i.contents.tenantName) LIKE :needle'];
        $query->setParameter('needle', LikePattern::containing(mb_strtolower($filter->search ?? '')));

        if (null !== $filter->number) {
            $conditions[] = 'i.tenancyNumber = :number';
            $query->setParameter('number', $filter->number);
        }

        $query->andWhere(implode(' OR ', $conditions));
    }
}
