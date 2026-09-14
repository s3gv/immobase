<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Application;

use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Settings\Contract\SettingsPermissions;
use App\Tests\Module\Auth\Fixture\Accounts;
use App\Tests\Module\Auth\Fixture\InMemoryPermissionAssignments;
use PHPUnit\Framework\TestCase;

/**
 * Was ein Konto tatsaechlich darf.
 *
 * Die Vereinigung aus Rollen und direkten Zuweisungen — und die Sonderfaelle,
 * an denen ein Rechtemodell sonst still danebenliegt: der Administrator und
 * Schluessel, die es nicht mehr gibt.
 */
final class EffectivePermissionsTest extends TestCase
{
    private InMemoryPermissionAssignments $assignments;

    private EffectivePermissions $effective;

    protected function setUp(): void
    {
        $this->assignments = new InMemoryPermissionAssignments();
        $this->effective = new EffectivePermissions($this->assignments, Accounts::catalogue());
    }

    public function testRightsAreTheUnionOfRolesAndDirectGrants(): void
    {
        $accounting = Accounts::role('Buchhaltung');
        $this->assignments->setForRole($accounting->id(), GrantedPermissions::of([SettingsPermissions::VIEW]));

        $user = Accounts::member('bucht@example.org', $accounting);
        $this->assignments->setForUser($user->id(), GrantedPermissions::of([PartyPermissions::VIEW]));

        self::assertSame(
            [PartyPermissions::VIEW, SettingsPermissions::VIEW],
            $this->effective->of($user)->toList(),
            'Die Reihenfolge ist alphabetisch — parties vor settings',
        );
    }

    /** Mehrere Rollen addieren sich; sie nehmen einander nichts. */
    public function testSeveralRolesAddUp(): void
    {
        $one = Accounts::role('Eine');
        $other = Accounts::role('Andere');
        $this->assignments->setForRole($one->id(), GrantedPermissions::of([PartyPermissions::VIEW]));
        $this->assignments->setForRole($other->id(), GrantedPermissions::of([PartyPermissions::EDIT]));

        $user = Accounts::member('beides@example.org', $one, $other);

        self::assertTrue($this->effective->allows($user, PartyPermissions::VIEW));
        self::assertTrue($this->effective->allows($user, PartyPermissions::EDIT));
    }

    /**
     * Der Administrator hat alles, ohne dass eine einzige Zeile in der
     * Datenbank steht — auch das, was erst morgen dazukommt.
     */
    public function testTheAdministratorHasEverythingWithoutAnyAssignment(): void
    {
        $admin = Accounts::member('admin@example.org', Role::administrator());

        self::assertSame(Accounts::catalogue()->keys(), $this->effective->of($admin)->toList());
    }

    /**
     * Zuordnungen zu Schluesseln, die es nicht mehr gibt, gewaehren nichts.
     *
     * Sie koennen entstehen, wenn ein Bereich verschwindet. Der Testlauf sieht
     * sie nicht — er liest den Code —, und deshalb muss die Berechnung sie
     * uebergehen, statt sich auf ein Aufraeumen zu verlassen.
     */
    public function testUnknownKeysInTheDatabaseGrantNothing(): void
    {
        $role = Accounts::role('Alt');
        $this->assignments->setForRole($role->id(), GrantedPermissions::of(['abrechnung.view', PartyPermissions::VIEW]));

        $user = Accounts::member('alt@example.org', $role);

        self::assertSame([PartyPermissions::VIEW], $this->effective->of($user)->toList());
        self::assertFalse($this->effective->allows($user, 'abrechnung.view'));
    }

    /**
     * Was aus einer Rolle kommt, ist am Konto nicht abwaehlbar — die
     * Kontoseite braucht die Trennung, um das Kaestchen abzuschalten.
     */
    public function testRoleGrantsAreSeparableFromDirectOnes(): void
    {
        $role = Accounts::role('Pflege');
        $this->assignments->setForRole($role->id(), GrantedPermissions::of([PartyPermissions::VIEW]));

        $user = Accounts::member('pflege@example.org', $role);
        $this->assignments->setForUser($user->id(), GrantedPermissions::of([PartyPermissions::DELETE]));

        self::assertSame([PartyPermissions::VIEW], $this->effective->fromRoles($user)->toList());
    }

    /** Ein Konto ohne Rechte hat keine — und keinen Fehler. */
    public function testAnAccountWithoutAnyGrantHasNothing(): void
    {
        self::assertTrue($this->effective->of(Accounts::member('leer@example.org'))->isEmpty());
    }
}
