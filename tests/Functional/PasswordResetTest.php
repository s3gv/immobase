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
 * "Passwort vergessen".
 *
 * Der Kern ist nicht, dass eine E-Mail ankommt, sondern dass die Seite
 * schweigt: bekannte und unbekannte Adressen bekommen dieselbe Antwort. Sonst
 * ist das Formular eine Auskunft darueber, wer hier ein Konto hat.
 */
final class PasswordResetTest extends WebTestCase
{
    private const string EMAIL = 'vergesslich@example.org';

    protected function tearDown(): void
    {
        self::removeUser();

        parent::tearDown();
    }

    public function testTheAnswerIsTheSameForKnownAndUnknownAddresses(): void
    {
        $client = self::createClient();
        self::givenUser();

        $known = self::ask($client, self::EMAIL);
        $unknown = self::ask($client, 'gibt-es-nicht@example.org');

        self::assertSame($known, $unknown, 'Die Seite verrät nicht, welche Adressen es gibt');
        self::assertStringContainsString('Wenn es ein Konto', $known);
    }

    public function testAMalformedAddressIsAnsweredTheSameWay(): void
    {
        $client = self::createClient();

        self::assertStringContainsString('Wenn es ein Konto', self::ask($client, 'keine-adresse'));
    }

    public function testTheFormIsReachableFromTheSignInPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertSelectorExists('a[href="/passwort/vergessen"]');
    }

    /**
     * Wer sein Passwort zuruecksetzt, tut das oft, weil jemand anders es
     * kennt. Offene Sitzungen muessen dann enden.
     *
     * Das erledigt Symfony von selbst: der Kontext-Listener vergleicht bei
     * jeder Anfrage den Passwort-Hash im Sitzungs-Token mit dem in der
     * Datenbank. Der Test steht hier, weil das eine Zusicherung ist, auf die
     * wir uns verlassen — und keine, die wir selbst geschrieben haben.
     */
    public function testChangingThePasswordEndsOpenSessions(): void
    {
        $client = self::createClient();
        self::givenUser();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => self::EMAIL,
            '_password' => 'die katze schlaeft auf dem sofa',
        ]));
        $client->request('GET', '/');

        self::assertResponseIsSuccessful('Angemeldet');

        self::changePasswordBehindTheirBack();

        $client->request('GET', '/');

        self::assertResponseRedirects('/login', null, 'Die offene Sitzung ist beendet');
    }

    /**
     * Wie es der Ablauf zum Zuruecksetzen tut: ein neuer Hash am Konto.
     */
    private static function changePasswordBehindTheirBack(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = $users->findByEmail(Email::fromString(self::EMAIL));
        self::assertInstanceOf(User::class, $user);

        $user->changePassword($hasher->hashPassword($user, 'ein ganz anderes langes passwort'));
        $users->save($user);
    }

    /**
     * Die Antwort auf eine Anfrage — ohne den Hintergrund.
     *
     * Der Umriss im Hintergrund steht an einer Stelle, die aus der Uhrzeit
     * folgt. Zwei Anfragen kurz nacheinander bekommen deshalb verschiedene
     * Transformationen, und ein Vergleich der ganzen Seite verglich am Ende
     * zwei Zeitpunkte statt zweier Antworten.
     */
    private static function ask(KernelBrowser $client, string $email): string
    {
        $crawler = $client->request('GET', '/passwort/vergessen');
        $client->submit($crawler->selectButton('Link anfordern')->form(['email' => $email]));

        $answer = $client->getCrawler()->filter('main');

        return $answer->count() > 0 ? $answer->html() : (string) $client->getResponse()->getContent();
    }

    private static function givenUser(): void
    {
        self::removeUser();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User($users->nextNumber(), Email::fromString(self::EMAIL));
        $user->changePassword($hasher->hashPassword($user, 'die katze schlaeft auf dem sofa'));
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
