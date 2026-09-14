<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;

/**
 * Rollen anlegen, umbenennen, loeschen.
 *
 * Die Gruende gegen eine Aenderung stehen hier und nicht im Controller: die
 * Uebersicht schaltet Knoepfe danach ab, und derselbe Grund muss beim Klick
 * noch einmal geprueft werden. Ein abgeschalteter Knopf haelt niemanden auf,
 * der das Formular nachbaut.
 */
final readonly class ManageRoles
{
    public function __construct(
        private RoleRepository $roles,
        private EffectivePermissions $effective,
    ) {
    }

    /** @return 'role.protected'|null */
    public function reasonAgainstRenaming(Role $role): ?string
    {
        return $role->isSystem() ? 'role.protected' : null;
    }

    /** @return 'role.protected'|'role.in_use'|null */
    public function reasonAgainstDeleting(Role $role): ?string
    {
        if ($role->isSystem()) {
            return 'role.protected';
        }

        return ($this->roles->userCounts()[$role->id()] ?? 0) > 0 ? 'role.in_use' : null;
    }

    /** @return 'role.name_taken'|null */
    public function reasonAgainstName(RoleName $name, ?Role $except = null): ?string
    {
        $existing = $this->roles->byName($name);

        if (null === $existing || $existing->id() === $except?->id()) {
            return null;
        }

        return 'role.name_taken';
    }

    public function create(RoleName $name): Role
    {
        $role = Role::named($name);
        $this->roles->save($role);

        return $role;
    }

    public function rename(Role $role, RoleName $name): void
    {
        $role->rename($name);
        $this->roles->save($role);
    }

    public function delete(Role $role): void
    {
        $role->refuseIfProtected();

        // Die Rechte der Rolle raeumt der Fremdschluessel mit weg. Sie hier
        // vorher zu leeren hiesse, sie auch dann zu verlieren, wenn das
        // Loeschen gleich abgelehnt wird — die Sperre gegen zugeordnete
        // Konten greift erst im Bestand.
        $this->roles->remove($role);
        $this->effective->forget();
    }
}
