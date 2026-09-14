<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use InvalidArgumentException;

/**
 * Welche Rollen ein Konto traegt.
 *
 * Mindestens eine, immer. Ein Konto ohne Rolle kann nichts und waere eine
 * Sackgasse: es meldet sich an und sieht eine leere Anwendung, ohne dass
 * jemandem auffiele, warum.
 */
final readonly class AssignRoles
{
    public function __construct(
        private RoleRepository $roles,
        private UserRepository $users,
        private EffectivePermissions $effective,
    ) {
    }

    /**
     * @param list<string> $roleIds
     *
     * @return 'role.assignment.none'|'role.assignment.unknown'|null
     */
    public function reasonAgainst(array $roleIds): ?string
    {
        if ([] === $roleIds) {
            return 'role.assignment.none';
        }

        return \count($this->resolve($roleIds)) === \count(array_unique($roleIds))
            ? null
            : 'role.assignment.unknown';
    }

    /**
     * @param list<string> $roleIds
     *
     * @throws InvalidArgumentException wenn die Liste leer ist oder eine Rolle fehlt
     */
    public function to(User $user, array $roleIds): void
    {
        $reason = $this->reasonAgainst($roleIds);

        if (null !== $reason) {
            throw new InvalidArgumentException($reason);
        }

        $user->assignRoles($this->resolve($roleIds));
        $this->users->save($user);
        $this->effective->forget();
    }

    /**
     * Die Rollen zu diesen Kennungen — unbekannte fallen weg.
     *
     * Oeffentlich, weil das Einladen sie braucht: dort entsteht das Konto
     * erst, es gibt also noch nichts, dem sich etwas zuweisen liesse.
     *
     * @param list<string> $roleIds
     *
     * @return list<Role>
     */
    public function resolve(array $roleIds): array
    {
        $found = [];

        foreach (array_unique($roleIds) as $id) {
            $role = $this->roles->byId($id);

            if (null !== $role) {
                $found[] = $role;
            }
        }

        return $found;
    }
}
