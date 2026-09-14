<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Auth\Domain\UserStatus;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Benutzerverwaltung — und vor allem ihre Sperren.
 *
 * Die interessanten Faelle sind hier die, in denen etwas *nicht* passieren
 * darf: sich selbst loeschen, den letzten Administrator abschalten, als
 * gewoehnliches Konto die Seite ueberhaupt sehen.
 */
final class UserAdministrationTest extends WebTestCase
{
    use SignsIn;

    private const string OTHER = 'kollegin@example.org';

    protected function tearDown(): void
    {
        self::removeOther();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testTheListIsClosedToOrdinaryAccounts(): void
    {
        $client = self::signedInAs();

        $client->request('GET', '/benutzer');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheListShowsAccountsWithTheirState(): void
    {
        $client = self::admin();
        self::givenOther();

        $client->request('GET', '/benutzer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sabine Kollegin', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Eingeladen', (string) $client->getResponse()->getContent());
    }

    public function testFilteringByState(): void
    {
        $client = self::admin();
        self::givenOther();

        $client->request('GET', '/benutzer?status=active');

        self::assertStringNotContainsString(self::OTHER, (string) $client->getResponse()->getContent());

        $client->request('GET', '/benutzer?status=invited');

        self::assertStringContainsString(self::OTHER, (string) $client->getResponse()->getContent());
    }

    /**
     * Deaktivierte Konten stehen nicht im Weg — aber bereit.
     *
     * Eine offene Einladung dagegen bleibt sichtbar: sie ist keine
     * Vergangenheit, sondern Arbeit, die noch aussteht.
     */
    public function testDeactivatedAccountsAreHiddenUntilAsked(): void
    {
        $client = self::admin();
        $other = self::givenOther();
        self::post($client, '/benutzer/'.$other->number().'/aktivierung', '/benutzer/'.$other->number());

        $client->request('GET', '/benutzer');
        self::assertStringNotContainsString(self::OTHER, (string) $client->getResponse()->getContent());

        $client->request('GET', '/benutzer?vergangene=1');
        self::assertStringContainsString(self::OTHER, (string) $client->getResponse()->getContent());
    }

    public function testSearchingByNumber(): void
    {
        $client = self::admin();
        $other = self::givenOther();

        $client->request('GET', '/benutzer?q='.$other->number());

        self::assertStringContainsString(self::OTHER, (string) $client->getResponse()->getContent());
    }

    /** Ein Prozentzeichen ist ein Zeichen, kein Platzhalter — und eine Suche nach „ä" rechnet nicht ab. */
    public function testASearchTakesWildcardsLiterallyAndPagesWithUmlauts(): void
    {
        $client = self::admin();
        self::givenOther();

        $client->request('GET', '/benutzer?q='.rawurlencode('%'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::OTHER, (string) $client->getResponse()->getContent());

        $client->request('GET', '/benutzer?q='.rawurlencode('ä'));
        self::assertResponseIsSuccessful();
    }

    public function testDeactivatingAndReactivating(): void
    {
        $client = self::admin();
        $other = self::givenOther();

        self::post($client, '/benutzer/'.$other->number().'/aktivierung', '/benutzer/'.$other->number());

        self::assertSame(UserStatus::Deactivated, self::other()->status());

        self::post($client, '/benutzer/'.$other->number().'/aktivierung', '/benutzer/'.$other->number());

        // Ohne Passwort geht es zurueck nach "eingeladen" — "aktiv" ohne
        // Passwort verspraeche etwas, das die Anmeldung nicht halten kann.
        self::assertSame(UserStatus::Invited, self::other()->status());
    }

    public function testDeleting(): void
    {
        $client = self::admin();
        $other = self::givenOther();

        self::post($client, '/benutzer/'.$other->number().'/loeschen', '/benutzer');

        $client->request('GET', '/benutzer/'.$other->number());

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Auf dem eigenen Konto gibt es die Knoepfe gar nicht — mit Begruendung
     * statt kommentarlos gesperrt.
     *
     * Dass auch ein nachgebautes Formular abgewiesen wird, prueft
     * ManageUserTest: die Regel sitzt in der Anwendungsschicht, und der
     * Controller fragt sie, bevor er irgendetwas tut.
     */
    public function testTheOwnAccountOffersNoDangerousButtons(): void
    {
        $client = self::admin();
        $me = self::me();

        $client->request('GET', '/benutzer/'.$me->number());

        self::assertSelectorNotExists('form[action*="loeschen"]', 'Kein Löschen-Formular');
        self::assertSelectorNotExists('form[action*="aktivierung"]', 'Kein Deaktivieren-Formular');
        self::assertSelectorTextContains('.ib-note', 'eigene Konto');
    }

    public function testTheLastAdministratorIsMarkedAsProtected(): void
    {
        $client = self::admin();

        // Ein zweites Konto, aber kein Administrator: die Sperre muss
        // trotzdem greifen — sie zaehlt Administratoren, nicht Konten.
        $other = self::givenOther();

        $client->request('GET', '/benutzer');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="delete-'.$other->number().'"', $html, 'Fremdes Konto: löschbar');
        self::assertStringNotContainsString('id="delete-'.self::me()->number().'"', $html, 'Eigenes Konto: nicht');
    }

    protected static function testEmail(): string
    {
        return 'administration-test@example.org';
    }

    private static function admin(): KernelBrowser
    {
        $client = self::signedInAs(asAdministrator: true);
        self::removeOther();

        return $client;
    }

    /**
     * Schickt ein Formular so ab, wie der Browser es taete — mit dem Token aus
     * der Seite, auf der der Knopf steht.
     */
    private static function post(KernelBrowser $client, string $url, string $expected): void
    {
        $number = (int) preg_replace('/\D/', '', explode('/', $url)[2] ?? '0');

        $token = $client->request('GET', '/benutzer/'.$number)
            ->filter('form[action="'.$url.'"] input[name="_token"]')->attr('value');

        $client->request('POST', $url, ['_token' => (string) $token]);

        self::assertResponseRedirects($expected);
    }

    private static function givenOther(): User
    {
        $users = self::users();

        $user = new User($users->nextNumber(), Email::fromString(self::OTHER));
        $user->nameYourself(PersonName::of('Sabine', 'Kollegin', 'Buchhaltung'));
        $users->save($user);

        return $user;
    }

    private static function other(): User
    {
        return self::reload(self::OTHER);
    }

    private static function me(): User
    {
        return self::reload(self::testEmail());
    }

    private static function reload(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $user = self::users()->findByEmail(Email::fromString($email));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function users(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private static function removeOther(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::OTHER)
            ->execute();
    }
}
