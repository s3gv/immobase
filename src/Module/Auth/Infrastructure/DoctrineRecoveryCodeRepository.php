<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Domain\RecoveryCode;
use App\Module\Auth\Domain\RecoveryCodeRepository;
use App\Module\Auth\Domain\TokenHasher;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRecoveryCodeRepository implements RecoveryCodeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenHasher $hasher,
    ) {
    }

    public function replaceAll(string $userId, array $codes): void
    {
        $this->removeAll($userId);

        foreach ($codes as $code) {
            $this->entityManager->persist($code);
        }

        $this->entityManager->flush();
    }

    public function findUnused(string $userId, string $text): ?RecoveryCode
    {
        $code = $this->entityManager->getRepository(RecoveryCode::class)->findOneBy([
            'userId' => $userId,
            'hash' => $this->hasher->hash(RecoveryCode::normalise($text)),
            'usedAt' => null,
        ]);

        return $code instanceof RecoveryCode ? $code : null;
    }

    /**
     * Wie beim Schluessel: die Datenbank entscheidet, wer zuerst da war. Zwei
     * gleichzeitige Anmeldungen mit demselben Zettel kommen sonst beide
     * durch.
     */
    public function consume(RecoveryCode $code, DateTimeImmutable $now): bool
    {
        $affected = $this->entityManager->createQuery(
            'UPDATE '.RecoveryCode::class.' c SET c.usedAt = :now WHERE c.id = :id AND c.usedAt IS NULL',
        )
            ->setParameter('now', $now)
            ->setParameter('id', $code->id())
            ->execute();

        if (1 !== $affected) {
            return false;
        }

        $code->markUsed($now);

        return true;
    }

    public function countUnused(string $userId): int
    {
        $count = $this->entityManager->createQuery(
            'SELECT COUNT(c.id) FROM '.RecoveryCode::class.' c WHERE c.userId = :user AND c.usedAt IS NULL',
        )
            ->setParameter('user', $userId)
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function removeAll(string $userId): void
    {
        $this->entityManager->createQuery('DELETE FROM '.RecoveryCode::class.' c WHERE c.userId = :user')
            ->setParameter('user', $userId)
            ->execute();

        $this->entityManager->clear();
    }
}
