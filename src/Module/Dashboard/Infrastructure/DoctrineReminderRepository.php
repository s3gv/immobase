<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure;

use App\Module\Dashboard\Domain\Reminder;
use App\Module\Dashboard\Domain\ReminderRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineReminderRepository implements ReminderRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Reminder $reminder): void
    {
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();
    }

    public function remove(Reminder $reminder): void
    {
        $this->entityManager->remove($reminder);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?Reminder
    {
        return $this->entityManager->getRepository(Reminder::class)->find($id);
    }

    public function between(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<Reminder> $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Reminder::class, 'r')
            ->where('r.userId = :owner')
            ->andWhere('r.dueAt >= :from')
            ->andWhere('r.dueAt <= :to')
            ->orderBy('r.dueAt', 'ASC')
            ->setParameter('owner', $userId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        return $found;
    }

    public function nextFor(string $userId, DateTimeImmutable $after, int $limit): array
    {
        return $this->around($userId, 'r.dueAt >= :when', 'ASC', $after, $limit);
    }

    public function recentFor(string $userId, DateTimeImmutable $before, int $limit): array
    {
        return $this->around($userId, 'r.dueAt < :when', 'DESC', $before, $limit);
    }

    /**
     * Die kurze Liste vor oder nach einem Zeitpunkt.
     *
     * Beide Richtungen fragen dasselbe und unterscheiden sich in zwei
     * Zeichen; zweimal geschrieben liefen sie frueher oder spaeter
     * auseinander — etwa darin, ob der Besitzer noch dabeisteht.
     *
     * @return list<Reminder>
     */
    private function around(string $userId, string $when, string $order, DateTimeImmutable $at, int $limit): array
    {
        /** @var list<Reminder> $found */
        $found = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Reminder::class, 'r')
            ->where('r.userId = :owner')
            ->andWhere($when)
            ->orderBy('r.dueAt', $order)
            ->setParameter('owner', $userId)
            ->setParameter('when', $at)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $found;
    }
}
