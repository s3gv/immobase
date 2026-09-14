<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

/**
 * Zugriff auf die Rollen.
 *
 * Wie beim Benutzer: die Schnittstelle in Domain, die Doctrine-Umsetzung in
 * Infrastructure.
 */
interface RoleRepository
{
    public function save(Role $role): void;

    /**
     * @throws RoleStillInUse  wenn der Rolle noch Konten zugeordnet sind
     * @throws RoleIsProtected wenn es die Systemrolle ist
     */
    public function remove(Role $role): void;

    public function byId(string $id): ?Role;

    public function byName(RoleName $name): ?Role;

    /**
     * Alle Rollen, Systemrolle zuerst, danach nach Namen.
     *
     * @return list<Role>
     */
    public function all(): array;

    /**
     * Die Systemrolle. Sie entsteht in der Migration und ist nicht loeschbar.
     */
    public function system(): Role;

    /**
     * Wie viele Konten haengen an welcher Rolle?
     *
     * Alle auf einmal und nicht je Rolle einzeln: die Uebersicht zeigt die
     * Zahl in jeder Zeile, und eine Abfrage je Zeile waere genau das Muster,
     * das Listen langsam macht.
     *
     * @return array<string, int> Rollen-Id auf Anzahl
     */
    public function userCounts(): array;
}
