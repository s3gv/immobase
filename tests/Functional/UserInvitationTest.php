<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Auth\Domain\UserStatus;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;

/**
 * Der Weg von der Einladung bis zur ersten Anmeldung.
 *
 * Die Reihenfolge ist hier fachlich wichtig: ein Konto wird nicht dadurch
 * aktiv, dass jemand den Ablauf durchklickt, sondern dadurch, dass sich Adresse
 * und Passwort danach tatsaechlich anmelden.
 */
final class UserInvitationTest extends WebTestCase
{
    use SignsIn;

    private const string INVITED = 'eingeladen@example.org';

    protected function tearDown(): void
    {
        self::removeInvited();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testInvitingCreatesAnInvitedAccount(): void
    {
        $client = self::admin();

        $client->request('POST', '/benutzer/neu', [
            '_token' => self::tokenFrom($client, '/benutzer/neu'),
            'email' => self::INVITED,
            'roles' => [self::someRole()->id()],
        ]);

        $user = self::invited();

        self::assertSame(UserStatus::Invited, $user->status());
        self::assertFalse($user->hasPassword(), 'Ein eingeladenes Konto hat noch kein Passwort');
        self::assertGreaterThanOrEqual(1001, $user->number());
    }

    public function testAnInvitedAccountCannotSignIn(): void
    {
        $client = self::admin();
        self::givenInvited();
        $client->getCookieJar()->clear();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => self::INVITED,
            '_password' => 'irgendetwas-langes-hier',
        ]));

        self::assertResponseRedirects('/login');
    }

    /**
     * Der ganze Weg: Passwort, Angaben, zweiter Faktor uebersprungen — und
     * danach die Anmeldung, die das Konto aktiv macht.
     */
    public function testTheWholeSetUpAndTheFirstSignIn(): void
    {
        $client = self::admin();
        $token = self::givenInvited();
        $client->getCookieJar()->clear();

        $client->request('POST', '/einladung/'.$token, [
            '_token' => self::tokenFrom($client, '/einladung/'.$token),
            'password' => 'die katze schlaeft auf dem sofa',
            'repeated' => 'die katze schlaeft auf dem sofa',
        ]);
        $client->followRedirect();

        self::assertTrue(self::invited()->hasPassword());

        $client->request('POST', '/einladung/'.$token, [
            '_token' => self::tokenFrom($client, '/einladung/'.$token),
            'givenName' => 'Erika',
            'familyName' => 'Muster',
            'jobTitle' => 'Sachbearbeiterin',
        ]);
        $client->followRedirect();

        self::assertSame('Erika Muster', self::invited()->displayName());
        self::assertSame(UserStatus::Invited, self::invited()->status(), 'Noch nicht aktiv');

        $client->request('POST', '/einladung/'.$token, [
            '_token' => self::tokenFrom($client, '/einladung/'.$token),
            'skip' => '1',
        ]);

        self::assertResponseRedirects('/login');

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => self::INVITED,
            '_password' => 'die katze schlaeft auf dem sofa',
        ]));

        self::assertResponseRedirects('/');
        self::assertSame(UserStatus::Active, self::invited()->status(), 'Die erste Anmeldung schaltet frei');
        self::assertNotNull(self::invited()->lastSignInAt());
    }

    public function testTheLinkWorksOnlyOnce(): void
    {
        $client = self::admin();
        $token = self::givenInvited();
        $client->getCookieJar()->clear();

        self::runThroughSetUp($client, $token);

        $client->request('GET', '/einladung/'.$token);

        self::assertSelectorTextContains('.ib-heading', 'nicht mehr gültig');
    }

    /**
     * Abgelaufen, verbraucht, erfunden: dieselbe Seite und derselbe
     * Statuscode. Ein Unterschied waere eine Auskunft darueber, welche Konten
     * es gibt.
     */
    public function testAnUnknownLinkLooksLikeAnExpiredOne(): void
    {
        $client = self::createClient();
        $client->request('GET', '/einladung/gibt-es-nicht');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ib-heading', 'nicht mehr gültig');
    }

    /**
     * Beim Zuruecksetzen ist das Passwort der erste Schritt — auch wenn es
     * laengst eines gibt. Der Ablauf leitete den Schritt zunaechst aus dem
     * Konto ab und sprang deshalb sofort zum zweiten Faktor: ein
     * Passwort-Zuruecksetzen, das kein Passwort setzt.
     */
    public function testResettingStartsAtThePassword(): void
    {
        $client = self::admin();
        $token = self::givenInvited();
        $client->getCookieJar()->clear();

        self::runThroughSetUp($client, $token);

        $reset = self::issue(TokenPurpose::Reset);
        $client->request('GET', '/passwort/neu/'.$reset);

        self::assertSelectorTextContains('.ib-flow__title', 'Passwort');
        self::assertSelectorExists('#password');
    }

    public function testAFreshInvitationInvalidatesTheOldLink(): void
    {
        $client = self::admin();
        $old = self::givenInvited();

        $number = self::invited()->number();
        $client->request('POST', '/benutzer/'.$number.'/einladen', [
            '_token' => self::tokenFrom($client, '/benutzer/'.$number, '_token'),
        ]);

        $client->getCookieJar()->clear();
        $client->request('GET', '/einladung/'.$old);

        self::assertSelectorTextContains('.ib-heading', 'nicht mehr gültig');
    }

    protected static function testEmail(): string
    {
        return 'invitation-test@example.org';
    }

    private static function runThroughSetUp(KernelBrowser $client, string $token): void
    {
        foreach ([
            ['password' => 'die katze schlaeft auf dem sofa', 'repeated' => 'die katze schlaeft auf dem sofa'],
            ['givenName' => 'Erika', 'familyName' => 'Muster', 'jobTitle' => ''],
            ['skip' => '1'],
        ] as $step) {
            $client->request('POST', '/einladung/'.$token, [
                '_token' => self::tokenFrom($client, '/einladung/'.$token),
                ...$step,
            ]);
        }
    }

    private static function admin(): KernelBrowser
    {
        $client = self::signedInAs(asAdministrator: true);
        self::removeInvited();

        return $client;
    }

    /** @return string der Klartext eines frischen Schluessels fuer dieses Konto */
    private static function issue(TokenPurpose $purpose): string
    {
        $tokens = self::getContainer()->get(TokenRepository::class);
        self::assertInstanceOf(TokenRepository::class, $tokens);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $issued = SignInToken::issue(self::invited()->id(), $purpose, $clock->now(), self::hasher());
        $tokens->issue($issued->token, $clock->now());

        return $issued->plain;
    }

    /** @return string der Klartext des Einladungsschluessels */
    private static function givenInvited(): string
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $tokens = self::getContainer()->get(TokenRepository::class);
        self::assertInstanceOf(TokenRepository::class, $tokens);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $user = new User($users->nextNumber(), Email::fromString(self::INVITED));
        $user->assignRoles([self::someRole()]);
        $users->save($user);

        $issued = SignInToken::issue($user->id(), TokenPurpose::Invite, $clock->now(), self::hasher());
        $tokens->issue($issued->token, $clock->now());

        return $issued->plain;
    }

    /**
     * Eine Rolle, die nicht die Systemrolle ist.
     *
     * Ohne sie zeigt das Einladungsformular nur den Hinweis, erst Rollen
     * anzulegen — ein Konto ohne Rolle kann nichts.
     */
    private static function someRole(): Role
    {
        $roles = self::getContainer()->get(RoleRepository::class);
        self::assertInstanceOf(RoleRepository::class, $roles);

        $name = RoleName::fromString('Mitarbeiter');
        $role = $roles->byName($name) ?? Role::named($name);
        $roles->save($role);

        return $role;
    }

    private static function hasher(): TokenHasher
    {
        $hasher = self::getContainer()->get(TokenHasher::class);
        self::assertInstanceOf(TokenHasher::class, $hasher);

        return $hasher;
    }

    private static function invited(): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = $users->findByEmail(Email::fromString(self::INVITED));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Das Token aus dem tatsaechlich gezeichneten Formular.
     *
     * Selbst erzeugen ginge auch, braeuchte aber eine Sitzung im Container —
     * und pruefte dann etwas anderes als das, was ein Browser schickt.
     */
    private static function tokenFrom(KernelBrowser $client, string $url, string $field = '_token'): string
    {
        $crawler = $client->request('GET', $url);

        // Gesucht wird im Seiteninhalt: die Kopfzeile traegt seit den
        // Erinnerungen ihr eigenes Formular mit eigenem Token, und das erste
        // Token der Seite ist damit nicht mehr das gemeinte. Die
        // Einladungsseite hat keine Kopfzeile — dort greift der Rueckfall.
        $inside = $crawler->filter('main input[name="'.$field.'"]');
        $crawler = $inside->count() > 0 ? $inside : $crawler;

        if (0 === $crawler->filter('input[name="'.$field.'"]')->count()) {
            self::fail(\sprintf(
                "Kein Token unter %s (Status %d):\n%s",
                $url,
                $client->getResponse()->getStatusCode(),
                substr((string) $client->getResponse()->getContent(), 0, 900),
            ));
        }

        $value = $crawler->filter('input[name="'.$field.'"]')->attr('value');

        self::assertIsString($value, 'Das Formular unter '.$url.' trägt kein Token');

        return $value;
    }

    private static function removeInvited(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::INVITED)
            ->execute();
    }
}
