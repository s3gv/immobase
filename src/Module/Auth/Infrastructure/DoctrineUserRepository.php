<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Domain\SecondFactorSettings;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserFilter;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Auth\Domain\UserStatus;
use App\Shared\Contact\Email;
use App\Shared\Search\LikePattern;
use App\Shared\Ui\Page;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerGuard $managers,
    ) {
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function remove(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function findByEmail(Email $email): ?User
    {
        return $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email->toString()]);
    }

    public function byId(string $id): ?User
    {
        return $this->entityManager->getRepository(User::class)->find($id);
    }

    public function byNumber(int $number): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['number' => $number]);
    }

    public function forParty(string $partyId): ?User
    {
        // Ueber den Abfragebaumeister und nicht ueber findOneBy(): der Pfad
        // geht durch das eingebettete Objekt, und das versteht findOneBy()
        // nicht.
        $found = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.speaksFor.partyId = :party')
            ->setParameter('party', $partyId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $found instanceof User ? $found : null;
    }

    public function nextNumber(): int
    {
        $next = $this->entityManager->getConnection()->fetchOne("SELECT nextval('auth_user_number_seq')");

        if (!is_numeric($next)) {
            throw new RuntimeException('Die Sequenz auth_user_number_seq hat keine Nummer geliefert.');
        }

        return (int) $next;
    }

    public function countMatching(UserFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(u.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(UserFilter $filter, Page $page): array
    {
        /** @var list<User> $users */
        $users = $this->restricted($filter, 'u')
            ->orderBy('u.number', 'ASC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $users;
    }

    public function consumeTotpStep(User $user, SecondFactorSettings $accepted): bool
    {
        $step = $accepted->usedStep();

        if (null === $step) {
            return false;
        }

        $affected = $this->entityManager->createQuery(
            'UPDATE '.User::class.' u SET u.factor.usedStep = :step
             WHERE u.id = :id AND (u.factor.usedStep IS NULL OR u.factor.usedStep < :step)',
        )
            ->setParameter('step', $step)
            ->setParameter('id', $user->id())
            ->execute();

        if (1 !== $affected) {
            return false;
        }

        $user->useSecondFactor($accepted);

        return true;
    }

    public function countActiveManagers(string $permissionKey, ?string $exceptUserId = null): int
    {
        return $this->managers->count($permissionKey, $exceptUserId);
    }

    public function guardingManagers(string $permissionKey, callable $change): mixed
    {
        return $this->managers->guarding($permissionKey, $change);
    }

    /**
     * Der Pfad geht ueber das eingebettete Objekt: u.state.status, nicht
     * u.status. Der falsche Pfad faellt erst zur Laufzeit auf — genau das ist
     * im Stammdatenmodul schon einmal passiert.
     */
    private function restricted(UserFilter $filter, string $select): QueryBuilder
    {
        // Portalkonten kommen in der Benutzerverwaltung nicht vor. Hier in
        // der Abfrage und nicht im Nachhinein: eine Liste, die sie laedt und
        // danach wegwirft, zaehlt sie in der Seitenzahl trotzdem mit — und der
        // naechste Aufrufer vergisst das Wegwerfen.
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(User::class, 'u')
            ->where('u.speaksFor.partyId IS NULL');

        if (null !== $filter->status) {
            $query->andWhere('u.state.status = :status')->setParameter('status', $filter->status);
        } elseif (!$filter->withPast) {
            // Deaktivierte stehen bereit, aber nicht im Weg.
            $query->andWhere('u.state.status <> :off')->setParameter('off', UserStatus::Deactivated);
        }

        if ($filter->administratorsOnly) {
            $query->join('u.roles', 'r')->andWhere('r.isSystem = true');
        }

        if (null !== $filter->search) {
            $this->restrictToSearch($query, $filter->search);
        }

        return $query;
    }

    /**
     * Sucht ueber Name, Adresse und — wenn die Eingabe eine Zahl ist — die
     * Kontonummer.
     *
     * Der Name wird kleingeschrieben verglichen, die Adresse ebenfalls: sie
     * steht ohnehin kleingeschrieben in der Datenbank. Die Nummer wird genau
     * verglichen, damit "100" nicht jede Nummer trifft, die eine 100 enthaelt.
     */
    private function restrictToSearch(QueryBuilder $query, string $search): void
    {
        $conditions = [
            'LOWER(u.name.givenName) LIKE :search',
            'LOWER(u.name.familyName) LIKE :search',
            'u.email LIKE :search',
        ];
        $query->setParameter('search', LikePattern::containing($search));

        if (ctype_digit($search)) {
            $conditions[] = 'u.number = :number';
            $query->setParameter('number', (int) $search);
        }

        $query->andWhere(implode(' OR ', $conditions));
    }
}
