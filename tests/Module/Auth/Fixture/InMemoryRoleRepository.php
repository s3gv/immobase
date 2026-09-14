<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Fixture;

use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\Rbac\RoleStillInUse;
use RuntimeException;

/**
 * Rollen im Speicher.
 *
 * Die Zahl der Konten je Rolle wird gesetzt und nicht gezaehlt: dieser
 * Bestand kennt keine Benutzer, und die Tests, die ihn benutzen, wollen den
 * Fall „noch jemand zugeordnet" ohnehin stellen und nicht herstellen.
 */
final class InMemoryRoleRepository implements RoleRepository
{
    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, int> */
    private array $users = [];

    /**
     * Die Rechtetabelle, an der ON DELETE CASCADE haengt.
     *
     * In der Datenbank raeumt der Fremdschluessel die Rechte einer geloeschten
     * Rolle mit weg. Ohne diese Nachbildung zeigte ein Test hier etwas, das im
     * Betrieb anders ausgeht.
     */
    private ?InMemoryPermissionAssignments $assignments = null;

    public function cascadingTo(InMemoryPermissionAssignments $assignments): self
    {
        $this->assignments = $assignments;

        return $this;
    }

    public function add(Role $role, int $users = 0): Role
    {
        $this->roles[$role->id()] = $role;
        $this->users[$role->id()] = $users;

        return $role;
    }

    public function save(Role $role): void
    {
        $this->roles[$role->id()] = $role;
        $this->users[$role->id()] ??= 0;
    }

    public function remove(Role $role): void
    {
        $role->refuseIfProtected();

        $users = $this->users[$role->id()] ?? 0;

        if ($users > 0) {
            throw new RoleStillInUse($users);
        }

        unset($this->roles[$role->id()], $this->users[$role->id()]);

        $this->assignments?->setForRole($role->id(), GrantedPermissions::none());
    }

    public function byId(string $id): ?Role
    {
        return $this->roles[$id] ?? null;
    }

    public function byName(RoleName $name): ?Role
    {
        foreach ($this->roles as $role) {
            if ($role->name()->equals($name)) {
                return $role;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->roles);
    }

    public function system(): Role
    {
        foreach ($this->roles as $role) {
            if ($role->isSystem()) {
                return $role;
            }
        }

        throw new RuntimeException('Dieser Bestand hat keine Systemrolle.');
    }

    public function userCounts(): array
    {
        return $this->users;
    }
}
