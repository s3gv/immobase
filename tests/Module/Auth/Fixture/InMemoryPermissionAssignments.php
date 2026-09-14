<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Fixture;

use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;

/**
 * Die beiden Zuordnungstabellen im Speicher.
 *
 * Fuer Tests der Rechteberechnung, die keine Datenbank brauchen.
 */
final class InMemoryPermissionAssignments implements PermissionAssignments
{
    /** @var array<string, GrantedPermissions> */
    private array $roles = [];

    /** @var array<string, GrantedPermissions> */
    private array $users = [];

    public function ofRole(string $roleId): GrantedPermissions
    {
        return $this->roles[$roleId] ?? GrantedPermissions::none();
    }

    public function ofRoles(array $roleIds): array
    {
        $found = [];

        foreach ($roleIds as $id) {
            if (isset($this->roles[$id])) {
                $found[$id] = $this->roles[$id];
            }
        }

        return $found;
    }

    public function ofUser(string $userId): GrantedPermissions
    {
        return $this->users[$userId] ?? GrantedPermissions::none();
    }

    public function setForRole(string $roleId, GrantedPermissions $permissions): void
    {
        $this->roles[$roleId] = $permissions;
    }

    public function setForUser(string $userId, GrantedPermissions $permissions): void
    {
        $this->users[$userId] = $permissions;
    }

    public function forgetUnknown(array $known): int
    {
        $removed = 0;

        foreach ($this->roles as $id => $granted) {
            $this->roles[$id] = $granted->knownOnly($known);
            $removed += $granted->count() - $this->roles[$id]->count();
        }

        foreach ($this->users as $id => $granted) {
            $this->users[$id] = $granted->knownOnly($known);
            $removed += $granted->count() - $this->users[$id]->count();
        }

        return $removed;
    }

    /**
     * Der ganze Bestand, um ihn spaeter wieder herzustellen.
     *
     * Die Attrappe eines Speichers, der Transaktionen kennt, muss sie auch
     * nachbilden — sonst zeigt ein Test einen Stand, den es im Betrieb nach
     * einem Ruecklauf nicht gaebe.
     *
     * @return array{roles: array<string, GrantedPermissions>, users: array<string, GrantedPermissions>}
     */
    public function snapshot(): array
    {
        return ['roles' => $this->roles, 'users' => $this->users];
    }

    /**
     * @param array{roles: array<string, GrantedPermissions>, users: array<string, GrantedPermissions>} $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->roles = $snapshot['roles'];
        $this->users = $snapshot['users'];
    }

    public function countsPerRole(): array
    {
        return array_map(static fn (GrantedPermissions $g): int => $g->count(), $this->roles);
    }
}
