<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\SystemRole;
use App\Module\Auth\Domain\User;

/**
 * Jedes Recht des Katalogs mit der Herkunft fuer ein bestimmtes Konto.
 *
 * Zwei Seiten leben davon: „Meine Rechte" zeigt nur die gewaehrten, die
 * Zusatzrechte am Benutzer zeigen alle — die aus einer Rolle gesetzt und
 * abgeschaltet, mit der Rolle als Grund.
 */
final readonly class ExplainPermissions
{
    public function __construct(
        private PermissionAssignments $assignments,
        private PermissionCatalogue $catalogue,
    ) {
    }

    /**
     * @return array<string, PermissionOrigin> Schluessel auf Herkunft, in der Reihenfolge des Katalogs
     */
    public function forUser(User $user): array
    {
        $direct = $this->assignments->ofUser($user->id());
        $byRole = $this->rolesGranting($user);
        $administrator = $user->isAdministrator();

        $origins = [];

        foreach ($this->catalogue->all() as $permission) {
            $origins[$permission->key()] = new PermissionOrigin(
                $permission,
                $byRole[$permission->key()] ?? [],
                $direct->has($permission->key()),
                $administrator,
            );
        }

        return $origins;
    }

    /**
     * Nur die gewaehrten, nach Bereich gruppiert — so steht es unter „Mein Konto".
     *
     * @return array<string, list<PermissionOrigin>>
     */
    public function grantedByArea(User $user): array
    {
        $areas = [];

        foreach ($this->forUser($user) as $origin) {
            if ($origin->isGranted()) {
                $areas[$origin->permission->area][] = $origin;
            }
        }

        return $areas;
    }

    /**
     * @return array<string, list<string>> Rechteschluessel auf Rollennamen
     */
    private function rolesGranting(User $user): array
    {
        $roles = $user->assignedRoles();
        $names = [];

        foreach ($roles as $role) {
            $names[$role->id()] = $role->isSystem() ? SystemRole::NAME : $role->name()->toString();
        }

        $granting = [];

        foreach ($this->assignments->ofRoles(array_keys($names)) as $roleId => $granted) {
            foreach ($granted->toList() as $key) {
                $granting[$key][] = $names[$roleId] ?? '';
            }
        }

        return $granting;
    }
}
