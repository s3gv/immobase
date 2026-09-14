<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

/**
 * Die beiden Tabellen, in denen Rechteschluessel stehen.
 *
 * `auth_role_permission` und `auth_user_permission` halten blosse
 * Zeichenketten. Als Doctrine-Entities waeren das zwei Klassen mit
 * zusammengesetztem Schluessel, deren einziger Inhalt ein String ist —
 * Aufwand ohne Gegenwert. Sie werden deshalb direkt geschrieben, hinter
 * dieser Schnittstelle.
 *
 * Unbekannte Schluessel duerfen hier stehen: ein Bereich kann verschwinden,
 * seine Zuordnungen bleiben. Sie gewaehren nichts — gefiltert wird beim
 * Berechnen — und `immobase:permission:prune` raeumt sie weg.
 */
interface PermissionAssignments
{
    public function ofRole(string $roleId): GrantedPermissions;

    /**
     * @param list<string> $roleIds
     *
     * @return array<string, GrantedPermissions> Rollen-Id auf ihre Rechte
     */
    public function ofRoles(array $roleIds): array;

    public function ofUser(string $userId): GrantedPermissions;

    public function setForRole(string $roleId, GrantedPermissions $permissions): void;

    public function setForUser(string $userId, GrantedPermissions $permissions): void;

    /**
     * Loescht alle Zuordnungen zu Schluesseln ausserhalb des Katalogs.
     *
     * @param list<string> $known
     *
     * @return int Anzahl der entfernten Zeilen
     */
    public function forgetUnknown(array $known): int;

    /**
     * Wie viele Rechte haengen an welcher Rolle?
     *
     * @return array<string, int> Rollen-Id auf Anzahl
     */
    public function countsPerRole(): array;
}
