<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTokenRepository implements TokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenHasher $hasher,
    ) {
    }

    public function issue(SignInToken $token, DateTimeImmutable $now): void
    {
        // Sperren, entwerten und ausstellen — zusammen oder gar nicht.
        $this->entityManager->wrapInTransaction(function () use ($token, $now): void {
            $this->lockOwner($token->userId());
            $this->invalidateOpen($token->userId(), $token->purpose(), $now);
            $this->entityManager->persist($token);
        });
    }

    public function findUsable(string $plain, TokenPurpose $purpose, DateTimeImmutable $now): ?SignInToken
    {
        $token = $this->entityManager->getRepository(SignInToken::class)->findOneBy([
            'hash' => $this->hasher->hash($plain),
            'purpose' => $purpose,
        ]);

        return null !== $token && $token->isUsable($now) ? $token : null;
    }

    /**
     * Eine bedingte Abfrage, kein Lesen mit anschliessendem Schreiben.
     *
     * Die Datenbank entscheidet, wer zuerst da war: nur der Aufruf, der genau
     * eine Zeile aendert, hat den Schluessel verbraucht. Alle anderen gehen
     * leer aus, auch wenn sie ihn gleichzeitig als offen gelesen haben.
     */
    public function consume(SignInToken $token, DateTimeImmutable $now): bool
    {
        $affected = $this->entityManager->createQuery(
            'UPDATE '.SignInToken::class.' t SET t.usedAt = :now
             WHERE t.id = :id AND t.usedAt IS NULL AND t.expiresAt > :now',
        )
            ->setParameter('now', $now)
            ->setParameter('id', $token->id())
            ->execute();

        if (1 !== $affected) {
            return false;
        }

        $token->markUsed($now);

        return true;
    }

    public function revokeOpen(string $userId, TokenPurpose $purpose, DateTimeImmutable $now): void
    {
        $this->invalidateOpen($userId, $purpose, $now);
    }

    public function findOpenInvite(string $userId, DateTimeImmutable $now): ?SignInToken
    {
        $token = $this->entityManager->getRepository(SignInToken::class)->findOneBy(
            ['userId' => $userId, 'purpose' => TokenPurpose::Invite, 'usedAt' => null],
            ['expiresAt' => 'DESC'],
        );

        return null !== $token && $token->isUsable($now) ? $token : null;
    }

    public function prune(DateTimeImmutable $before): int
    {
        $removed = $this->entityManager->createQuery(
            'DELETE FROM '.SignInToken::class.' t WHERE t.expiresAt < :before',
        )
            ->setParameter('before', $before)
            ->execute();

        return is_numeric($removed) ? (int) $removed : 0;
    }

    /**
     * Sperrt die Zeile des Kontos fuer die Dauer der Transaktion.
     *
     * Die Transaktion allein genuegt nicht. Sie schuetzt nur, solange es
     * ueberhaupt einen offenen Schluessel gibt, den das Entwerten sperren
     * kann — bei der *ersten* Anforderung trifft das UPDATE keine Zeile und
     * sperrt darum auch nichts. Zwei gleichzeitige erste Anfragen liefen
     * aneinander vorbei und legten beide einen gueltigen Schluessel an.
     *
     * Die Kontozeile gibt es dagegen immer. Wer sie hat, stellt aus; wer
     * wartet, sieht danach, was der erste hinterlassen hat — und entwertet
     * es.
     *
     * Bewusst kein eindeutiger Index ueber (Konto, Zweck): der verwandelte
     * dieselbe Verschraenkung in einen Fehler statt in ein kurzes Warten, und
     * zwei Administratoren, die gleichzeitig einladen, saehen eine
     * Fehlerseite.
     */
    private function lockOwner(string $userId): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT id FROM auth_user WHERE id = :id FOR UPDATE',
            ['id' => $userId],
        );
    }

    private function invalidateOpen(string $userId, TokenPurpose $purpose, DateTimeImmutable $now): void
    {
        $this->entityManager->createQuery(
            'UPDATE '.SignInToken::class.' t SET t.usedAt = :now
             WHERE t.userId = :user AND t.purpose = :purpose AND t.usedAt IS NULL',
        )
            ->setParameter('now', $now)
            ->setParameter('user', $userId)
            ->setParameter('purpose', $purpose)
            ->execute();
    }
}
