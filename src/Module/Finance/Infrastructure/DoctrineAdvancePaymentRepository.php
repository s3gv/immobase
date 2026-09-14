<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure;

use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdvancePaymentRepository implements AdvancePaymentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function byId(string $id): ?AdvancePayment
    {
        return $this->entityManager->getRepository(AdvancePayment::class)->find($id);
    }

    public function saveAll(array $payments): void
    {
        foreach ($payments as $payment) {
            $this->entityManager->persist($payment);
        }

        $this->entityManager->flush();
    }

    /**
     * Nicht mehr faellige Eintraege verschwinden.
     *
     * Ein Flush fuer alle und nicht einer je Eintrag: Doctrine schliesst den
     * Manager, wenn ein Flush scheitert, und der naechste Durchlauf haette
     * dann keinen mehr.
     *
     * Wer davon in einer Abrechnung steckt, wird **vor** dem Aufruf
     * aussortiert — dafuer gibt es den Vertrag, den Billing bereitstellt. Der
     * Fremdschluessel darunter ist die letzte Linie und keine Abfrage: laesst
     * er einen Eintrag nicht los, ist das ein Fehler in der Anwendung und
     * keine Auskunft, die jemand lesen soll.
     */
    public function removeAll(array $payments): void
    {
        if ([] === $payments) {
            return;
        }

        foreach ($payments as $payment) {
            $this->entityManager->remove($payment);
        }

        $this->entityManager->flush();
    }

    public function forYear(array $unitIds, int $fiscalYear): array
    {
        if ([] === $unitIds) {
            return [];
        }

        /** @var list<AdvancePayment> $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AdvancePayment::class, 'p')
            ->where('p.unitId IN (:units)')
            ->andWhere('p.fiscalYear = :year')
            ->setParameter('units', $unitIds)
            ->setParameter('year', $fiscalYear)
            ->orderBy('p.dueOn', 'ASC')
            ->addOrderBy('p.kind', 'ASC')
            ->getQuery()
            ->getResult();

        return $payments;
    }

    public function unsettledBefore(DateTimeImmutable $day): array
    {
        /** @var list<AdvancePayment> $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AdvancePayment::class, 'p')
            ->where('p.dueOn < :day')
            ->andWhere('p.settled = false')
            ->setParameter('day', $day)
            ->orderBy('p.dueOn', 'ASC')
            ->addOrderBy('p.kind', 'ASC')
            ->getQuery()
            ->getResult();

        return $payments;
    }

    public function dueUntil(array $unitIds, DateTimeImmutable $day): array
    {
        if ([] === $unitIds) {
            return [];
        }

        /** @var list<AdvancePayment> $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AdvancePayment::class, 'p')
            ->where('p.unitId IN (:units)')
            ->andWhere('p.dueOn <= :day')
            ->setParameter('units', $unitIds)
            ->setParameter('day', $day)
            ->orderBy('p.dueOn', 'ASC')
            ->addOrderBy('p.kind', 'ASC')
            ->getQuery()
            ->getResult();

        return $payments;
    }
}
