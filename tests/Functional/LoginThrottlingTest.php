<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Die Bremse gegen Durchprobieren an der Anmeldung.
 *
 * Sie war einmal konfiguriert und wurde nie benutzt — Passwortversuche waren
 * damit unbegrenzt moeglich. Der Test steht hier, weil eine Bremse, die
 * niemand ausloest, nicht von einer fehlenden zu unterscheiden ist.
 */
final class LoginThrottlingTest extends WebTestCase
{
    use ForgetsRateLimits;

    private const string EMAIL = 'gebremst@example.org';
    private const string PASSWORD = 'die katze schlaeft auf dem sofa';

    protected function setUp(): void
    {
        self::bootKernel();
        self::forgetRateLimits();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetRateLimits();
        self::removeUser();

        parent::tearDown();
    }

    public function testTooManyWrongPasswordsAreStopped(): void
    {
        $client = self::createClient();
        self::givenUser();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::signInWith($client, 'falsch-'.$attempt);
        }

        $html = self::signInWith($client, 'auch-falsch');

        self::assertStringContainsString('Zu viele Versuche', $html);
    }

    /**
     * Und danach hilft auch das richtige Passwort nicht mehr — sonst waere die
     * Bremse nur eine Anzeige.
     */
    public function testTheRightPasswordDoesNotGetPastTheBrake(): void
    {
        $client = self::createClient();
        self::givenUser();

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            self::signInWith($client, 'falsch-'.$attempt);
        }

        self::signInWith($client, self::PASSWORD);

        $client->request('GET', '/');

        self::assertResponseRedirects('/login', null, 'Nicht angemeldet');
    }

    /** @return string die Anmeldeseite nach dem Versuch */
    private static function signInWith(KernelBrowser $client, string $password): string
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => self::EMAIL,
            '_password' => $password,
        ]));

        return $client->followRedirect()->html();
    }

    private static function givenUser(): void
    {
        self::removeUser();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User($users->nextNumber(), Email::fromString(self::EMAIL));
        $user->changePassword($hasher->hashPassword($user, self::PASSWORD));
        $user->activate();
        $users->save($user);
    }

    private static function removeUser(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::EMAIL)
            ->execute();
    }
}
