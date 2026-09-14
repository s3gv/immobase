<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Infrastructure;

use App\Module\Portal\Domain\Proposal;
use App\Module\Portal\Domain\ProposalDecision;
use App\Module\Portal\Domain\ProposalRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class DoctrineProposalRepository implements ProposalRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countOpen(): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Proposal::class, 'p')
            ->where('p.decision = :pending')
            ->setParameter('pending', ProposalDecision::Pending->value)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Von Hand und nicht ueber wrapInTransaction().
     *
     * Das nimmt nur bei einer Ausnahme zurueck und schliesst dabei den Entity
     * Manager — der Rest der Anfrage haette dann keinen mehr. Hier ist die
     * Absage der gewoehnliche Fall: sie nimmt zurueck und raeumt den Speicher
     * auf, damit der naechste flush() nicht doch noch hinschreibt, was gerade
     * zurueckgenommen wurde.
     */
    public function atomically(callable $work): bool
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $done = $work();
        } catch (Throwable $failure) {
            $connection->rollBack();
            $this->entityManager->close();

            throw $failure;
        }

        if (!$done) {
            $connection->rollBack();
            $this->entityManager->clear();

            return false;
        }

        $this->entityManager->flush();
        $connection->commit();

        return true;
    }

    /**
     * Eine bedingte Anweisung und keine Pruefung im Speicher.
     *
     * Dasselbe Muster wie beim zweiten Faktor: zwei gleichzeitige Anfragen
     * laden beide den offenen Vorschlag, und beide faenden ihn offen. Die
     * Datenbank entscheidet, wer ihn bekommt — wer null Zeilen aendert, war
     * der Zweite.
     *
     * Erst danach wird die geladene Entity nachgezogen, damit sie nicht etwas
     * anderes behauptet als die Zeile.
     */
    public function decideOnce(
        Proposal $proposal,
        ProposalDecision $decision,
        string $userId,
        DateTimeImmutable $at,
    ): bool {
        $affected = $this->entityManager->createQuery(
            'UPDATE '.Proposal::class.' p
             SET p.decision = :decision, p.decidedByUserId = :user, p.decidedAt = :at
             WHERE p.id = :id AND p.decision = :pending',
        )
            ->setParameter('decision', $decision->value)
            ->setParameter('user', $userId)
            ->setParameter('at', $at)
            ->setParameter('id', $proposal->id())
            ->setParameter('pending', ProposalDecision::Pending->value)
            ->execute();

        if (1 !== $affected) {
            return false;
        }

        $proposal->decide($decision, $userId, $at);

        return true;
    }
}
