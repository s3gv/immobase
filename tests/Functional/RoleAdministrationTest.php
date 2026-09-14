<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Party\Domain\PartyPermissions;
use App\Shared\Contact\Email;
use Collator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Seite „Rollen und Rechte" — von einem Konto aus bedient, das *nicht*
 * Administrator ist.
 *
 * Genau darum geht es bei diesem Bereich: die Rechteverwaltung soll
 * weiterreichbar sein, ohne jemanden zum Administrator zu machen.
 */
final class RoleAdministrationTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string ROLE = 'Prüfrolle';

    /** Die Seite hat zwei Abschnitte: die Rollen und die Matrix. */
    private const string ROLES_PAGE = '/rollen';

    private const string MATRIX_PAGE = '/rollen?abschnitt=rechte';

    private const string ADMIN = 'rollen.admin@example.org';

    protected function tearDown(): void
    {
        self::removeAdministrator();
        self::removeRole();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testTheListIsClosedWithoutTheViewPermission(): void
    {
        $client = self::signedInWith([]);

        $client->request('GET', '/rollen');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheMatrixIsVisibleButNotSavableWithoutTheEditPermission(): void
    {
        $client = self::signedInWith([AuthPermissions::ROLES_VIEW]);
        self::givenRole();

        $client->request('GET', self::ROLES_PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::ROLE, (string) $client->getResponse()->getContent());

        $client->request('GET', self::MATRIX_PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('role_permissions', (string) $client->getResponse()->getContent());

        $client->request('POST', '/rollen/rechte', ['_token' => 'egal']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Die Bereiche stehen nach ihrer Beschriftung, nicht nach ihrem Schluessel.
     *
     * Der Schluessel ist englisch, die Oberflaeche nicht: nach Schluessel
     * sortiert stuende „Protokoll" (audit) vor „Abrechnungen" (billing).
     */
    public function testTheAreasAreOrderedByTheirLabel(): void
    {
        $client = self::signedInWith([AuthPermissions::ROLES_VIEW]);

        $crawler = $client->request('GET', self::MATRIX_PAGE);
        $labels = $crawler->filter('.ib-matrix__group th')->each(static fn ($th): string => trim($th->text()));
        $sorted = $labels;
        (new Collator('de'))->sort($sorted);

        self::assertGreaterThan(5, \count($labels));
        self::assertSame($sorted, $labels);
    }

    public function testAnAccountWithRolesEditSavesTheMatrixWithoutBeingAdministrator(): void
    {
        $client = self::signedInWith([AuthPermissions::ROLES_VIEW, AuthPermissions::ROLES_EDIT, PartyPermissions::VIEW, PartyPermissions::EDIT]);
        self::givenAdministrator();
        $role = self::givenRole();

        $client->request('POST', '/rollen/rechte', [
            '_token' => self::tokenFrom($client, '/rollen/rechte', self::MATRIX_PAGE),
            'permissions' => [$role->id() => [PartyPermissions::VIEW, PartyPermissions::EDIT, AuthPermissions::USERS_EDIT]],
        ]);

        self::assertResponseRedirects(self::MATRIX_PAGE, message: 'Zurück in den Abschnitt, in dem gearbeitet wurde');

        self::assertSame(
            [PartyPermissions::EDIT, PartyPermissions::VIEW],
            self::assignments()->ofRole($role->id())->toList(),
            'Was man selbst nicht hat, vergibt man nicht — users.edit bleibt draussen',
        );
    }

    public function testARightTheActorLacksIsDisabledInTheMatrix(): void
    {
        $client = self::signedInWith([AuthPermissions::ROLES_VIEW, AuthPermissions::ROLES_EDIT]);
        $role = self::givenRole();

        $crawler = $client->request('GET', self::MATRIX_PAGE);

        self::assertNull($crawler->filter('input[name="permissions['.$role->id().'][]"][value="'.AuthPermissions::ROLES_EDIT.'"]')->attr('disabled'));
        self::assertNotNull($crawler->filter('input[name="permissions['.$role->id().'][]"][value="'.AuthPermissions::USERS_EDIT.'"]')->attr('disabled'));
    }

    public function testTheAdministratorColumnIsCheckedAndDisabled(): void
    {
        $client = self::signedInWith([AuthPermissions::ROLES_VIEW]);

        $crawler = $client->request('GET', self::MATRIX_PAGE);
        $administrator = self::roles()->system();

        $boxes = $crawler->filter('input[name="permissions['.$administrator->id().'][]"]');

        self::assertGreaterThan(0, $boxes->count());
        $boxes->each(static function ($box): void {
            self::assertNotNull($box->attr('checked'), 'Der Administrator hat immer alles');
            self::assertNotNull($box->attr('disabled'), 'Und deshalb lässt sich hier nichts einstellen');
        });
    }

    public function testCreatingRenamingAndDeletingARole(): void
    {
        $client = self::signedInWith([
            AuthPermissions::ROLES_VIEW,
            AuthPermissions::ROLES_EDIT,
            AuthPermissions::ROLES_DELETE,
        ]);

        self::post($client, '/rollen/neu', ['name' => self::ROLE]);
        $role = self::roles()->byName(RoleName::fromString(self::ROLE));
        self::assertInstanceOf(Role::class, $role);

        self::post($client, '/rollen/'.$role->id().'/umbenennen', ['name' => 'Umbenannt']);
        self::assertSame('Umbenannt', self::freshRole($role->id())->name()->toString());

        self::post($client, '/rollen/'.$role->id().'/loeschen');
        self::assertNull(self::roles()->byId($role->id()));
    }

    public function testARoleNameIsNotHandedOutTwice(): void
    {
        $client = self::signedInWith([AuthPermissions::ROLES_VIEW, AuthPermissions::ROLES_EDIT]);
        self::givenRole();

        self::post($client, '/rollen/neu', ['name' => mb_strtolower(self::ROLE)]);

        $client->followRedirect();
        self::assertStringContainsString(
            'Es gibt bereits eine Rolle mit diesem Namen.',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * Die Systemrolle traegt keine Aktionen — sie hat weder Umbenennen noch
     * Loeschen, und beides steht auch nicht als abgeschalteter Knopf da.
     */
    public function testTheSystemRoleCarriesNoActions(): void
    {
        $client = self::signedInWith([
            AuthPermissions::ROLES_VIEW,
            AuthPermissions::ROLES_EDIT,
            AuthPermissions::ROLES_DELETE,
        ]);
        $system = self::roles()->system();

        $crawler = $client->request('GET', '/rollen');

        self::assertCount(0, $crawler->filter('form[action$="/'.$system->id().'/umbenennen"]'));
        self::assertCount(0, $crawler->filter('form[action$="/'.$system->id().'/loeschen"]'));
    }

    protected static function testEmail(): string
    {
        return 'rollen@example.org';
    }

    /**
     * @param array<string, string> $fields
     */
    private static function post(KernelBrowser $client, string $url, array $fields = []): void
    {
        $client->request('POST', $url, [...$fields, '_token' => self::tokenFrom($client, $url)]);
    }

    /**
     * Das Token aus dem tatsaechlich gezeichneten Formular.
     *
     * Selbst erzeugen ginge auch, braeuchte aber eine Sitzung im Container —
     * und pruefte dann etwas anderes als das, was ein Browser schickt.
     */
    private static function tokenFrom(KernelBrowser $client, string $action, string $section = self::ROLES_PAGE): string
    {
        $crawler = $client->request('GET', $section);
        $field = $crawler->filter('form[action="'.$action.'"] input[name="_token"]');

        self::assertGreaterThan(0, $field->count(), 'Kein Formular für '.$action);

        return (string) $field->attr('value');
    }

    /**
     * Ein Konto, das Benutzer verwalten kann.
     *
     * Die Matrix nimmt einen Stand nur an, wenn danach noch jemand verwalten
     * kann. In der Testdatenbank steht das nicht von selbst da.
     */
    private static function givenAdministrator(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        if (null !== $users->findByEmail(Email::fromString(self::ADMIN))) {
            return;
        }

        $user = new User($users->nextNumber(), Email::fromString(self::ADMIN));
        $user->assignRoles([self::roles()->system()]);
        $user->changePassword('$2y$13$abcdefghijklmnopqrstuv');
        $user->activate();
        $users->save($user);
    }

    private static function givenRole(): Role
    {
        $role = self::roles()->byName(RoleName::fromString(self::ROLE)) ?? Role::named(RoleName::fromString(self::ROLE));
        self::roles()->save($role);

        return $role;
    }

    private static function freshRole(string $id): Role
    {
        $role = self::roles()->byId($id);
        self::assertInstanceOf(Role::class, $role);

        return $role;
    }

    private static function removeAdministrator(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = $users->findByEmail(Email::fromString(self::ADMIN));

        if (null !== $user) {
            $users->remove($user);
        }
    }

    private static function removeRole(): void
    {
        if (null === self::$kernel) {
            return;
        }

        foreach ([self::ROLE, 'Umbenannt'] as $name) {
            $role = self::roles()->byName(RoleName::fromString($name));

            if (null !== $role && !$role->isSystem()) {
                self::roles()->remove($role);
            }
        }
    }

    private static function roles(): RoleRepository
    {
        $roles = self::getContainer()->get(RoleRepository::class);
        self::assertInstanceOf(RoleRepository::class, $roles);

        return $roles;
    }

    private static function assignments(): PermissionAssignments
    {
        $assignments = self::getContainer()->get(PermissionAssignments::class);
        self::assertInstanceOf(PermissionAssignments::class, $assignments);

        return $assignments;
    }
}
