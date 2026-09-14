<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Application\ManageUser;
use App\Module\Auth\Application\Rbac\ManageRoles;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\Rbac\RoleStillInUse;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Party\Domain\PartyPermissions;
use App\Shared\Contact\Email;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Zwei Verwalter, die im selben Augenblick etwas wegnehmen.
 *
 * Beide Sperren dieses Moduls zaehlen erst und handeln dann. Auf einer
 * Verbindung ist das dicht; nebeneinander waere es das nicht, und der Ausgang
 * ist in beiden Faellen still: einmal eine Installation, in der niemand mehr
 * Benutzer verwalten kann, einmal ein Konto, das seine Rolle verliert, ohne
 * dass es irgendwo steht.
 *
 * Der Test haelt die Sperre auf der zweiten Verbindung fest und laesst die
 * erste hineinlaufen. Ohne die Sperre kaeme sie durch — und genau das waere
 * der Fehler.
 */
final class RbacConcurrencyTest extends KernelTestCase
{
    use UsesASecondConnection;

    private const string ONE = 'gleichzeitig.eins@example.org';

    private const string TWO = 'gleichzeitig.zwei@example.org';

    private const string ROLE = 'Gleichzeitig';

    protected function tearDown(): void
    {
        $this->cleanUp();
        $this->closeSecondConnection();

        parent::tearDown();
    }

    /**
     * Das Abschalten wartet, solange jemand anders die Verwalter haelt.
     *
     * Ohne die Sperre saehen beide Anfragen zwei Verwalter, hielten beide ihr
     * Konto fuer entbehrlich und schalteten beide ab.
     */
    public function testDeactivatingWaitsForAnotherChangeToTheManagers(): void
    {
        $first = self::givenAdministrator(self::ONE);
        self::givenAdministrator(self::TWO);

        $other = $this->secondConnection();
        $other->beginTransaction();
        // Die zweite Anfrage schaltet den *anderen* Verwalter ab und ist noch
        // nicht fertig. Genau darauf kommt es an: die eigene Zeile ist frei,
        // und ohne die Sperre auf der Menge liefe das Abschalten hier
        // ungehindert durch — die Zaehlung saehe den noch aktiven Kollegen.
        $other->executeStatement(
            "UPDATE auth_user SET status = 'deactivated' WHERE email = ?",
            [self::TWO],
        );

        self::assertTrue($this->deactivating($first), 'Das Abschalten lief an der Sperre vorbei');

        $other->rollBack();

        self::assertTrue(
            self::fresh(self::ONE)->status()->isActive(),
            'Und hat auch nichts hinterlassen',
        );
    }

    /**
     * Eine laufende Zuweisung ueberlebt einen Loeschversuch.
     *
     * Der stille Ausgang, um den es geht: das Loeschen zaehlt null Konten,
     * loescht — und das ON DELETE CASCADE nimmt die frisch festgeschriebene
     * Zuweisung mit weg. Der Benutzer verliert Rechte, ohne dass es irgendwo
     * steht.
     *
     * Was diesen Test von der Vorgaenger-Fassung unterscheidet, ist nicht das
     * Warten — PostgreSQL laesst das DELETE ohnehin warten, weil die Zuweisung
     * die Rollenzeile fuer ihren Fremdschluessel haelt. Entscheidend ist, dass
     * die *Zaehlung* jetzt hinter derselben Sperre steht: wer nach dem Warten
     * drankommt, zaehlt neu und sieht die Zuweisung. Vorher zaehlte er davor.
     */
    public function testAnAssignmentInFlightSurvivesAnAttemptToDeleteTheRole(): void
    {
        $role = self::givenRole();
        $user = self::givenAdministrator(self::ONE);

        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->executeStatement(
            'INSERT INTO auth_user_role (user_id, role_id) VALUES (?, ?)',
            [$user->id(), $role->id()],
        );

        self::assertTrue($this->deleting($role), 'Das Löschen lief an der Zuweisung vorbei');

        $other->commit();

        self::assertNotNull(self::roles()->byId($role->id()), 'Die Rolle steht noch');
        self::assertSame(
            1,
            self::assignedCount($role),
            'Und die Zuweisung auch — sie wurde nicht stillschweigend mitgelöscht',
        );
    }

    /**
     * Ein abgelehntes Loeschen laesst die Rechte der Rolle stehen.
     *
     * Sie vorab zu leeren und erst danach zu loeschen war bequem und falsch:
     * die Sperre gegen zugeordnete Konten greift erst im Bestand, und bis
     * dahin waren die Rechte fort — bei einer Rolle, die es danach noch gibt.
     */
    public function testARefusedDeletionLeavesThePermissionsAlone(): void
    {
        $role = self::givenRole();
        $user = self::givenAdministrator(self::ONE);

        self::assignments()->setForRole($role->id(), GrantedPermissions::of([PartyPermissions::VIEW]));
        $user->assignRoles([self::roles()->system(), $role]);
        self::users()->save($user);

        try {
            self::manageRoles()->delete($role);
            self::fail('Eine Rolle mit Konten darf sich nicht löschen lassen');
        } catch (RoleStillInUse) {
            // So soll es sein.
        }

        self::assertSame(
            [PartyPermissions::VIEW],
            self::assignments()->ofRole($role->id())->toList(),
        );
    }

    /**
     * @return bool ob die Aenderung an der Sperre haengen blieb
     */
    private function deactivating(User $user): bool
    {
        self::stopWaitingQuickly();

        $manage = self::getContainer()->get(ManageUser::class);
        self::assertInstanceOf(ManageUser::class, $manage);

        try {
            $manage->toggleActivation($user, 'jemand-anders');
        } catch (DbalException) {
            return true;
        }

        return false;
    }

    /**
     * @return bool ob das Loeschen an der Sperre haengen blieb
     */
    private function deleting(Role $role): bool
    {
        self::stopWaitingQuickly();

        try {
            self::roles()->remove($role);
        } catch (DbalException) {
            return true;
        }

        return false;
    }

    private static function givenAdministrator(string $email): User
    {
        $users = self::users();
        $user = new User($users->nextNumber(), Email::fromString($email));
        $user->assignRoles([self::roles()->system()]);
        $user->changePassword('$2y$13$abcdefghijklmnopqrstuv');
        $user->activate();
        $users->save($user);

        return $user;
    }

    private static function givenRole(): Role
    {
        $name = RoleName::fromString(self::ROLE);
        $role = self::roles()->byName($name) ?? Role::named($name);
        self::roles()->save($role);

        return $role;
    }

    private static function assignedCount(Role $role): int
    {
        $count = self::entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM auth_user_role WHERE role_id = ?',
            [$role->id()],
        );

        return is_numeric($count) ? (int) $count : -1;
    }

    private static function manageRoles(): ManageRoles
    {
        $manage = self::getContainer()->get(ManageRoles::class);
        self::assertInstanceOf(ManageRoles::class, $manage);

        return $manage;
    }

    private static function assignments(): PermissionAssignments
    {
        $assignments = self::getContainer()->get(PermissionAssignments::class);
        self::assertInstanceOf(PermissionAssignments::class, $assignments);

        return $assignments;
    }

    private static function fresh(string $email): User
    {
        self::entityManager()->clear();

        $user = self::users()->findByEmail(Email::fromString($email));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Aufgeraeumt wird ueber die eigene Verbindung — aber erst, nachdem die
     * zweite losgelassen hat.
     */
    private function cleanUp(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $this->closeSecondConnection();

        $connection = self::entityManager()->getConnection();
        $connection->executeStatement('DELETE FROM auth_user WHERE email IN (?, ?)', [self::ONE, self::TWO]);
        $connection->executeStatement('DELETE FROM auth_role WHERE name = ?', [self::ROLE]);
    }

    private static function users(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private static function roles(): RoleRepository
    {
        $roles = self::getContainer()->get(RoleRepository::class);
        self::assertInstanceOf(RoleRepository::class, $roles);

        return $roles;
    }
}
