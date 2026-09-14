<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\Rbac\RoleStillInUse;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineRoleRepository implements RoleRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Role $role): void
    {
        $this->entityManager->persist($role);
        $this->entityManager->flush();
    }

    /**
     * Zaehlen und Loeschen unter einer Sperre auf der Rolle selbst.
     *
     * Getrennt waere es ein Wettrennen mit stillem Ausgang: wird die Rolle
     * zwischen der Zaehlung und dem DELETE jemandem zugewiesen, raeumt das
     * ON DELETE CASCADE diese frische Zuordnung gleich mit weg. Der Benutzer
     * verlaere Rechte, ohne dass es irgendwo auffiele.
     *
     * Die Sperre auf der Rollenzeile faengt genau das: eine Zuweisung braucht
     * fuer ihren Fremdschluessel eine Sperre auf derselben Zeile und wartet
     * deshalb hier. Kommt sie zuerst, zaehlt diese Pruefung sie mit; kommt sie
     * danach, laeuft sie in den Fremdschluessel und schlaegt fehl, statt still
     * mitgeloescht zu werden.
     */
    public function remove(Role $role): void
    {
        $role->refuseIfProtected();

        $this->entityManager->getConnection()->transactional(
            function (Connection $connection) use ($role): void {
                $connection->executeQuery('SELECT id FROM auth_role WHERE id = ? FOR UPDATE', [$role->id()]);

                $users = $this->userCounts()[$role->id()] ?? 0;

                if ($users > 0) {
                    throw new RoleStillInUse($users);
                }

                // Die Rechte der Rolle gehen ueber ON DELETE CASCADE mit; sie
                // hier vorher zu leeren hiesse, sie auch dann zu verlieren,
                // wenn das Loeschen gleich abgelehnt wird.
                $this->entityManager->remove($role);
                $this->entityManager->flush();
            },
        );
    }

    public function byId(string $id): ?Role
    {
        return $this->entityManager->getRepository(Role::class)->find($id);
    }

    public function byName(RoleName $name): ?Role
    {
        /** @var Role|null $role */
        $role = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Role::class, 'r')
            ->where('LOWER(r.name) = :name')
            ->setParameter('name', mb_strtolower($name->toString()))
            ->getQuery()
            ->getOneOrNullResult();

        return $role;
    }

    public function all(): array
    {
        /** @var list<Role> $roles */
        $roles = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Role::class, 'r')
            ->orderBy('r.isSystem', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $roles;
    }

    public function system(): Role
    {
        /** @var Role|null $role */
        $role = $this->entityManager->getRepository(Role::class)->findOneBy(['isSystem' => true]);

        if (null === $role) {
            throw new RuntimeException('Es gibt keine Systemrolle. Ist die Migration gelaufen?');
        }

        return $role;
    }

    public function userCounts(): array
    {
        /** @var list<array{role_id: string, users: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT role_id, COUNT(*) AS users FROM auth_user_role GROUP BY role_id',
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['role_id']] = (int) $row['users'];
        }

        return $counts;
    }
}
