<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Application;

use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Application\Rbac\ManageRoles;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Party\Domain\PartyPermissions;
use App\Tests\Module\Auth\Fixture\Accounts;
use App\Tests\Module\Auth\Fixture\InMemoryPermissionAssignments;
use App\Tests\Module\Auth\Fixture\InMemoryRoleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Die Gruende, aus denen ein Knopf auf der Rollenseite abgeschaltet dasteht.
 *
 * Sie stehen im Anwendungsfall und nicht im Controller, weil die Uebersicht
 * sie zum Zeichnen braucht und das Ausfuehren noch einmal dieselbe Antwort.
 */
final class ManageRolesTest extends TestCase
{
    private InMemoryRoleRepository $roles;

    private InMemoryPermissionAssignments $assignments;

    protected function setUp(): void
    {
        $this->assignments = new InMemoryPermissionAssignments();
        $this->roles = (new InMemoryRoleRepository())->cascadingTo($this->assignments);
    }

    public function testTheSystemRoleCarriesNoActions(): void
    {
        $system = $this->roles->add(Role::administrator());

        self::assertSame('role.protected', $this->manage()->reasonAgainstRenaming($system));
        self::assertSame('role.protected', $this->manage()->reasonAgainstDeleting($system));
    }

    public function testARoleWithAccountsCannotBeDeleted(): void
    {
        $role = $this->roles->add(Accounts::role('Buchhaltung'), users: 2);

        self::assertSame('role.in_use', $this->manage()->reasonAgainstDeleting($role));
    }

    public function testAnEmptyRoleCanBeDeleted(): void
    {
        $role = $this->roles->add(Accounts::role('Leer'));

        self::assertNull($this->manage()->reasonAgainstDeleting($role));

        $this->manage()->delete($role);

        self::assertNull($this->roles->byId($role->id()));
    }

    /**
     * Die Rechte gehen mit — ueber den Fremdschluessel.
     *
     * Zeilen, auf die keine Rolle mehr zeigt, faende auch prune nicht: sie
     * verweisen ja auf gueltige Schluessel.
     */
    public function testDeletingARoleTakesItsPermissionsWithIt(): void
    {
        $role = $this->roles->add(Accounts::role('Alt'));
        $this->assignments->setForRole($role->id(), GrantedPermissions::of([PartyPermissions::VIEW]));

        $this->manage()->delete($role);

        self::assertTrue($this->assignments->ofRole($role->id())->isEmpty());
    }

    public function testATakenNameIsRefusedRegardlessOfCase(): void
    {
        $this->roles->add(Accounts::role('Buchhaltung'));

        self::assertSame('role.name_taken', $this->manage()->reasonAgainstName(RoleName::fromString('buchhaltung')));
    }

    /** Beim Umbenennen zaehlt der eigene Name nicht als vergeben. */
    public function testARoleMayKeepItsOwnName(): void
    {
        $role = $this->roles->add(Accounts::role('Buchhaltung'));

        self::assertNull($this->manage()->reasonAgainstName(RoleName::fromString('Buchhaltung'), $role));
    }

    private function manage(): ManageRoles
    {
        return new ManageRoles(
            $this->roles,
            new EffectivePermissions($this->assignments, Accounts::catalogue()),
        );
    }
}
