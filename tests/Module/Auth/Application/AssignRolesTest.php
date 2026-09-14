<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Application;

use App\Module\Auth\Application\Rbac\AssignPermissions;
use App\Module\Auth\Application\Rbac\AssignRoles;
use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleIsProtected;
use App\Module\Party\Domain\PartyPermissions;
use App\Tests\Module\Auth\Fixture\Accounts;
use App\Tests\Module\Auth\Fixture\InMemoryPermissionAssignments;
use App\Tests\Module\Auth\Fixture\InMemoryRoleRepository;
use App\Tests\Module\Auth\Fixture\InMemoryUserRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Rollen und Rechte vergeben — und die Regeln, die dabei greifen.
 */
final class AssignRolesTest extends TestCase
{
    private InMemoryRoleRepository $roles;

    private InMemoryPermissionAssignments $assignments;

    private EffectivePermissions $effective;

    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        $this->roles = new InMemoryRoleRepository();
        $this->assignments = new InMemoryPermissionAssignments();
        $this->effective = new EffectivePermissions($this->assignments, Accounts::catalogue());
        // Ein Administrator im Bestand: sonst liesse die Matrix gar nichts
        // mehr zu — nach jedem Speichern koennte niemand mehr verwalten.
        $this->users = (new InMemoryUserRepository(Accounts::administrator('admin@example.org')))
            ->knowing($this->effective)
            ->rollingBack($this->assignments);
    }

    public function testAnAccountKeepsAtLeastOneRole(): void
    {
        $user = Accounts::member('jemand@example.org');

        self::assertSame('role.assignment.none', $this->assign()->reasonAgainst([]));

        $this->expectException(InvalidArgumentException::class);
        $this->assign()->to($user, []);
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        self::assertSame('role.assignment.unknown', $this->assign()->reasonAgainst(['gibtesnicht']));
    }

    public function testAssigningReplacesTheWholeSet(): void
    {
        $one = $this->roles->add(Accounts::role('Eine'));
        $other = $this->roles->add(Accounts::role('Andere'));
        $user = Accounts::member('wechsel@example.org', $one);

        $this->assign()->to($user, [$other->id()]);

        self::assertSame([$other->id()], array_map(static fn (Role $r): string => $r->id(), $user->assignedRoles()));
    }

    /**
     * Zusatzrechte koennen nur hinzufuegen.
     *
     * Was ohnehin aus einer Rolle kommt, wird nicht mit abgelegt: es stuende
     * sonst zweimal da, und beim Entziehen der Rolle bliebe es unbemerkt
     * stehen.
     */
    public function testExtraGrantsDoNotDuplicateWhatARoleAlreadyGives(): void
    {
        $role = $this->roles->add(Accounts::role('Pflege'));
        $this->assignments->setForRole($role->id(), GrantedPermissions::of([PartyPermissions::VIEW]));
        $user = Accounts::member('extra@example.org', $role);

        $this->permissions()->toUser(
            $user,
            [PartyPermissions::VIEW, PartyPermissions::EDIT],
            $this->effective->fromRoles($user),
        );

        self::assertSame([PartyPermissions::EDIT], $this->assignments->ofUser($user->id())->toList());
        self::assertTrue($this->effective->allows($user, PartyPermissions::VIEW), 'Kommt weiter aus der Rolle');
    }

    /** Unbekannte Schluessel kommen gar nicht erst hinein. */
    public function testUnknownKeysAreDroppedOnTheWayIn(): void
    {
        $role = $this->roles->add(Accounts::role('Neu'));

        $this->permissions()->toRole($role, ['abrechnung.view', PartyPermissions::VIEW]);

        self::assertSame([PartyPermissions::VIEW], $this->assignments->ofRole($role->id())->toList());
    }

    public function testTheSystemRoleRefusesAssignedPermissions(): void
    {
        $this->expectException(RoleIsProtected::class);

        $this->permissions()->toRole($this->roles->add(Role::administrator()), [PartyPermissions::VIEW]);
    }

    /**
     * Die Matrix laesst die Systemrolle liegen, statt in eine Ausnahme zu
     * laufen: abgeschaltete Kaestchen sendet ein nachgebautes Formular
     * trotzdem.
     */
    public function testTheMatrixSkipsTheSystemRole(): void
    {
        $system = $this->roles->add(Role::administrator());
        $role = $this->roles->add(Accounts::role('Buchhaltung'));

        $this->permissions()->saveMatrix([
            $system->id() => [PartyPermissions::DELETE],
            $role->id() => [PartyPermissions::VIEW],
        ], self::everything());

        self::assertTrue($this->assignments->ofRole($system->id())->isEmpty());
        self::assertSame([PartyPermissions::VIEW], $this->assignments->ofRole($role->id())->toList());
    }

    /**
     * Auch ueber die Matrix darf sich eine Installation nicht aussperren.
     *
     * Wer bei `users.edit` das letzte Haekchen entfernt, nimmt dem letzten
     * Verwalter sein Recht. Danach kaeme niemand mehr an die Benutzer — und
     * auch nicht mehr an die Matrix, um es zurueckzugeben.
     */
    public function testTheMatrixRefusesToRemoveTheLastManager(): void
    {
        $management = $this->roles->add(Accounts::role('Verwaltung'));
        $this->assignments->setForRole($management->id(), GrantedPermissions::of([AuthPermissions::USERS_EDIT]));

        $users = (new InMemoryUserRepository(Accounts::member('einzige@example.org', $management)))
            ->knowing($this->effective)
            ->rollingBack($this->assignments);

        $saved = $this->permissionsFor($users)->saveMatrix([$management->id() => [PartyPermissions::VIEW]], self::everything());

        self::assertFalse($saved);
        self::assertSame(
            [AuthPermissions::USERS_EDIT],
            $this->assignments->ofRole($management->id())->toList(),
            'Der alte Stand steht noch',
        );
    }

    /**
     * Solange jemand anders verwalten kann, ist die Matrix frei.
     */
    public function testTheMatrixMayTakeAwayARightSomeoneElseStillHas(): void
    {
        $management = $this->roles->add(Accounts::role('Verwaltung'));
        $this->assignments->setForRole($management->id(), GrantedPermissions::of([AuthPermissions::USERS_EDIT]));

        $users = (new InMemoryUserRepository(
            Accounts::member('zweite@example.org', $management),
            Accounts::administrator('admin@example.org'),
        ))->knowing($this->effective);

        self::assertTrue($this->permissionsFor($users)->saveMatrix([$management->id() => []], self::everything()));
        self::assertTrue($this->assignments->ofRole($management->id())->isEmpty());
    }

    /**
     * Wer nur Rollen bearbeiten darf, macht sich ueber die Matrix nicht zum
     * Administrator — und nimmt auch niemandem ein Recht, das er selbst nicht
     * hat.
     */
    public function testTheMatrixOnlyMovesRightsTheActorHas(): void
    {
        $own = $this->roles->add(Accounts::role('Rechteverwaltung'));
        $other = $this->roles->add(Accounts::role('Buchhaltung'));
        $this->assignments->setForRole($own->id(), GrantedPermissions::of([AuthPermissions::ROLES_VIEW, AuthPermissions::ROLES_EDIT]));
        $this->assignments->setForRole($other->id(), GrantedPermissions::of([PartyPermissions::DELETE]));

        $this->permissions()->saveMatrix([
            $own->id() => [AuthPermissions::ROLES_VIEW, AuthPermissions::ROLES_EDIT, AuthPermissions::USERS_EDIT],
            $other->id() => [AuthPermissions::ROLES_VIEW],
        ], GrantedPermissions::of([AuthPermissions::ROLES_VIEW, AuthPermissions::ROLES_EDIT]));

        self::assertEqualsCanonicalizing([AuthPermissions::ROLES_VIEW, AuthPermissions::ROLES_EDIT], $this->assignments->ofRole($own->id())->toList(), 'users.edit kam nicht dazu');
        self::assertEqualsCanonicalizing([AuthPermissions::ROLES_VIEW, PartyPermissions::DELETE], $this->assignments->ofRole($other->id())->toList(), 'parties.delete blieb stehen');
    }

    private static function everything(): GrantedPermissions
    {
        return GrantedPermissions::of(Accounts::catalogue()->keys());
    }

    private function assign(): AssignRoles
    {
        return new AssignRoles($this->roles, $this->users, $this->effective);
    }

    private function permissions(): AssignPermissions
    {
        return $this->permissionsFor($this->users);
    }

    private function permissionsFor(InMemoryUserRepository $users): AssignPermissions
    {
        return new AssignPermissions($this->assignments, $this->roles, Accounts::catalogue(), $this->effective, $users);
    }
}
