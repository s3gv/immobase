<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Party\Domain\PartyPermissions;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rollen und Zusatzrechte am fremden Konto — und die eigenen unter „Mein
 * Konto".
 */
final class UserRightsTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string OTHER = 'kollege@example.org';

    private const string ROLE = 'Objektbetreuung';

    protected function tearDown(): void
    {
        self::removeOther();
        self::removeTestUser();
        self::removeRole();

        parent::tearDown();
    }

    public function testInvitingWithoutARoleIsRefused(): void
    {
        $client = self::manager();
        self::givenRole();

        $client->request('POST', '/benutzer/neu', [
            '_token' => self::tokenFrom($client, '/benutzer/neu'),
            'email' => self::OTHER,
        ]);

        self::assertResponseIsSuccessful('Das Formular kommt zurück, statt ein Konto anzulegen');
        self::assertStringContainsString(
            'Bitte mindestens eine Rolle auswählen.',
            (string) $client->getResponse()->getContent(),
        );
        self::assertNull(self::find(self::OTHER));
    }

    public function testAnAccountWithUsersEditMayInviteAndAssignRoles(): void
    {
        $client = self::manager();
        $role = self::givenRole();

        $client->request('POST', '/benutzer/neu', [
            '_token' => self::tokenFrom($client, '/benutzer/neu'),
            'email' => self::OTHER,
            'roles' => [$role->id()],
        ]);

        $invited = self::find(self::OTHER);
        self::assertInstanceOf(User::class, $invited);
        self::assertSame([$role->id()], array_map(static fn (Role $r): string => $r->id(), $invited->assignedRoles()));
    }

    /**
     * Ein Konto ohne Rolle waere eine Sackgasse — deshalb bleibt die letzte
     * stehen, auch wenn das Formular ohne Häkchen zurückkommt.
     */
    public function testTheLastRoleCannotBeTakenAway(): void
    {
        $client = self::manager();
        $other = self::givenOther();

        $client->request('POST', '/benutzer/'.$other->number().'/rollen', [
            '_token' => self::tokenFrom($client, '/benutzer/'.$other->number(), 'rollen'),
            'roles' => [],
        ]);

        $client->followRedirect();

        self::assertStringContainsString(
            'Bitte mindestens eine Rolle auswählen.',
            (string) $client->getResponse()->getContent(),
        );
        self::assertCount(1, self::fresh($other->number())->assignedRoles());
    }

    /**
     * Zusatzrechte kommen hinzu; was schon aus einer Rolle kommt, wird nicht
     * noch einmal abgelegt.
     */
    public function testExtraPermissionsAreAddedOnTopOfTheRoles(): void
    {
        $client = self::manager();
        $other = self::givenOther();
        self::assignments()->setForRole(self::givenRole()->id(), GrantedPermissions::of([PartyPermissions::VIEW]));

        $client->request('POST', '/benutzer/'.$other->number().'/rechte', [
            '_token' => self::tokenFrom($client, '/benutzer/'.$other->number(), 'rechte'),
            'permissions' => [PartyPermissions::VIEW, PartyPermissions::EDIT],
        ]);

        self::assertSame([PartyPermissions::EDIT], self::assignments()->ofUser($other->id())->toList());
    }

    public function testWhatARoleGivesIsShownCheckedAndDisabled(): void
    {
        $client = self::manager();
        $other = self::givenOther();
        self::assignments()->setForRole(self::givenRole()->id(), GrantedPermissions::of([PartyPermissions::VIEW]));

        $crawler = $client->request('GET', '/benutzer/'.$other->number().'?abschnitt=rechte');
        $box = $crawler->filter('input[name="permissions[]"][value="'.PartyPermissions::VIEW.'"]');

        self::assertCount(1, $box);
        self::assertNotNull($box->attr('checked'));
        self::assertNotNull($box->attr('disabled'), 'Aus einer Rolle lässt es sich hier nicht wegnehmen');
    }

    /**
     * `users.edit` reicht, um die Systemrolle zu vergeben — mit Absicht.
     *
     * Wer fremde Konten verwaltet, laedt auch ein und vergibt Rollen. Eine
     * Sperre gegen „darf die Systemrolle nicht vergeben" waere nur scheinbar
     * eine: dieselbe Person legt sich sonst ein zweites Konto an und laedt es
     * an eine eigene Adresse ein.
     *
     * Der Test steht hier, damit die Entscheidung sichtbar bleibt. Wer sie
     * umdreht, kommt an ihm nicht vorbei.
     */
    public function testManagingUsersIncludesHandingOutTheSystemRole(): void
    {
        $client = self::manager();
        $other = self::givenOther();
        $administrator = self::roles()->system();

        $client->request('POST', '/benutzer/'.$other->number().'/rollen', [
            '_token' => self::tokenFrom($client, '/benutzer/'.$other->number(), 'rollen'),
            'roles' => [$administrator->id()],
        ]);

        self::assertTrue(self::fresh($other->number())->isAdministrator());
    }

    /** „Meine Rechte" zeigt Erklärung und Herkunft — sonst bliebe die Frage offen. */
    public function testMyPermissionsShowExplanationAndOrigin(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);

        $client->request('GET', '/mein-konto?abschnitt=rechte');
        $page = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Stammdaten anzeigen', $page);
        self::assertStringContainsString('Die Übersicht und die Detailseiten', $page);
        self::assertStringContainsString('über Rolle Test '.self::testEmail(), $page);
    }

    public function testAnAccountWithoutPermissionsIsToldSo(): void
    {
        $client = self::signedInWith([]);

        $client->request('GET', '/mein-konto?abschnitt=rechte');

        self::assertStringContainsString('Ihr Konto hat noch keine Rechte.', (string) $client->getResponse()->getContent());
    }

    protected static function testEmail(): string
    {
        return 'rechte@example.org';
    }

    /** Ein Konto, das Benutzer verwalten darf, ohne Administrator zu sein. */
    private static function manager(): KernelBrowser
    {
        return self::signedInWith([AuthPermissions::USERS_VIEW, AuthPermissions::USERS_EDIT]);
    }

    private static function givenRole(): Role
    {
        $name = RoleName::fromString(self::ROLE);
        $role = self::roles()->byName($name) ?? Role::named($name);
        self::roles()->save($role);

        return $role;
    }

    private static function givenOther(): User
    {
        $users = self::users();
        $user = new User($users->nextNumber(), Email::fromString(self::OTHER));
        $user->assignRoles([self::givenRole()]);
        $user->changePassword('$2y$13$abcdefghijklmnopqrstuv');
        $user->activate();
        $users->save($user);

        return $user;
    }

    private static function fresh(int $number): User
    {
        self::entityManager()->clear();

        $user = self::users()->byNumber($number);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function find(string $email): ?User
    {
        self::entityManager()->clear();

        return self::users()->findByEmail(Email::fromString($email));
    }

    /**
     * Das Token aus dem tatsaechlich gezeichneten Formular.
     *
     * Die Kontoseite hat drei Abschnitte; das gesuchte Formular steht in dem,
     * der es traegt.
     */
    private static function tokenFrom(KernelBrowser $client, string $url, string $form = ''): string
    {
        $crawler = $client->request('GET', '' === $form ? $url : $url.'?abschnitt='.$form);
        // Im Seiteninhalt und nicht in der Kopfzeile: die traegt seit den
        // Erinnerungen ihr eigenes Formular mit eigenem Token.
        $selector = '' === $form
            ? 'main input[name="_token"]'
            : 'form[action$="/'.$form.'"] input[name="_token"]';
        $field = $crawler->filter($selector);

        self::assertGreaterThan(0, $field->count(), 'Kein Formular auf '.$url);

        return (string) $field->first()->attr('value');
    }

    private static function removeOther(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $user = self::users()->findByEmail(Email::fromString(self::OTHER));

        if (null !== $user) {
            self::users()->remove($user);
        }
    }

    private static function removeRole(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $role = self::roles()->byName(RoleName::fromString(self::ROLE));

        if (null !== $role) {
            self::roles()->remove($role);
        }
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

    private static function assignments(): PermissionAssignments
    {
        $assignments = self::getContainer()->get(PermissionAssignments::class);
        self::assertInstanceOf(PermissionAssignments::class, $assignments);

        return $assignments;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
