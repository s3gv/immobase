<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    private const TEST_EMAIL = 'login-test@example.org';
    private const TEST_PASSWORD = 'ein-sehr-langes-testpasswort';

    protected function tearDown(): void
    {
        $this->removeTestUser();

        parent::tearDown();
    }

    public function testSuccessfulLoginLandsOnAReachablePage(): void
    {
        $client = self::createClient();
        $this->createTestUser();
        $client->followRedirects();

        $crawler = $client->request('GET', '/login');

        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => self::TEST_EMAIL,
            '_password' => self::TEST_PASSWORD,
        ]));

        // Der eigentliche Punkt: nach der Anmeldung landet man auf einer
        // existierenden Seite. Ohne default_target_path und ohne Route "/"
        // endete der Grundablauf hier mit 404.
        self::assertResponseIsSuccessful();
        self::assertSame('/', $client->getRequest()->getPathInfo());
        self::assertSelectorNotExists('form input[name="_username"]');
    }

    /** Die Adresse steht klein geschrieben am Konto; wie sie getippt wird, ist gleich. */
    public function testTheAddressIsFoundHoweverItIsWritten(): void
    {
        $client = self::createClient();
        $this->createTestUser();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => ' LOGIN-Test@Example.org ',
            '_password' => self::TEST_PASSWORD,
        ]));

        self::assertResponseRedirects('/');
    }

    public function testDashboardRedirectsAnonymousVisitorsToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageIsReachableWithoutAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_username"]');
    }

    public function testWrongCredentialsShowAnError(): void
    {
        $client = self::createClient();

        // Umleitungen automatisch folgen: geprüft wird, was der Nutzer am Ende
        // sieht, nicht die Zwischenschritte des Sicherheitssystems.
        $client->followRedirects();

        $crawler = $client->request('GET', '/login');

        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => 'nobody@example.org',
            '_password' => 'falsch',
        ]));

        self::assertSelectorExists('[role="alert"]');
    }

    public function testTheErrorDoesNotRevealWhetherTheAddressExists(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $crawler = $client->request('GET', '/login');

        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => 'nobody@example.org',
            '_password' => 'falsch',
        ]));

        $message = $client->getCrawler()->filter('[role="alert"]')->text();

        self::assertStringNotContainsStringIgnoringCase('nobody@example.org', $message);
        self::assertStringNotContainsStringIgnoringCase('nicht gefunden', $message);
    }

    private function createTestUser(): void
    {
        $this->removeTestUser();

        $container = self::getContainer();

        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User($users->nextNumber(), Email::fromString(self::TEST_EMAIL));
        $user->nameYourself(PersonName::of('Test', 'Konto'));
        $user->changePassword($hasher->hashPassword($user, self::TEST_PASSWORD));
        $user->activate();

        $users->save($user);
    }

    private function removeTestUser(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery(
            'DELETE FROM '.User::class.' u WHERE u.email = :email'
        )->setParameter('email', self::TEST_EMAIL)->execute();
    }
}
