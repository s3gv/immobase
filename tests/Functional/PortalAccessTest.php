<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Contract\PortalAccount;
use App\Module\Auth\Contract\PortalAccounts;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Die Trennung zwischen Portal und Verwalterbereich.
 *
 * Die Zusicherung, an der das ganze Modul haengt: **ein Portalkonto ist kein
 * Mitarbeiter mit weniger Rechten, sondern ueberhaupt keiner.** Es taucht in
 * der Rechteverwaltung nicht auf und kommt an keine einzige Adresse des
 * Verwalterbereichs.
 */
final class PortalAccessTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    /** Vor der Anmeldung erreichbar — und deshalb auch fuer ein Portalkonto. */
    private const array PUBLIC_ROUTES = [
        'app_login',
        'app_logout',
        'app_locale',
        'app_second_factor',
        'app_second_factor_qr',
        'app_password_forgotten',
        'app_plugin_theme_css',
    ];

    private const string PASSWORD = 'ein-sehr-langes-portalpasswort';

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();
        self::removeThePortalAccount();
        self::removeTheSmuggledRole();

        parent::tearDown();
    }

    /**
     * Ein Portalkonto kommt an **keine** Verwalteradresse.
     *
     * Geprueft ueber die Liste der tatsaechlich registrierten Routen und
     * nicht ueber eine Auswahl von Hand: so deckt dieser Test auch die Seite
     * ab, die naechste Woche dazukommt. Genau dort waere sonst die Luecke —
     * niemand denkt beim Anlegen einer neuen Seite an das Portal.
     */
    public function testAPortalAccountReachesNoInternalPage(): void
    {
        $client = self::signedInForParty(self::aPartyId());

        foreach (self::internalRoutes() as $name => $path) {
            $client->request('GET', $path);
            $status = $client->getResponse()->getStatusCode();

            // Die Datenschnittstelle der Plugins ist keine Seite: sie kennt
            // keine Sitzung und antwortet ohne Token mit 401. Ausgenommen ist
            // sie deshalb nicht — sie muss nur zeigen, dass sie zu ist, und
            // zwar auf ihre eigene Art.
            $closed = str_starts_with($path, '/api/') ? [401] : [403, 404];

            self::assertContains($status, $closed, $name.' ('.$path.') stand offen');
        }
    }

    /** Und ein Verwalterkonto kommt nicht ins Portal. */
    public function testAStaffAccountReachesNoPortalPage(): void
    {
        $client = self::signedInAs(asAdministrator: true);

        $client->request('GET', '/portal/daten');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Ein Portalkonto steht in keiner Benutzerliste.
     *
     * Es ist kein Mitarbeiter, und die Rechteverwaltung fuehrt Mitarbeiter.
     * Stuende es dort, koennte ihm jemand eine Rolle geben — und damit waere
     * die ganze Trennung aufgehoben.
     */
    public function testAPortalAccountIsNotInTheUserList(): void
    {
        $client = self::signedInAs(asAdministrator: true);
        self::buildTheProperty();
        $portal = self::aPortalAccountFor(self::anOwnerPartyId());

        $page = $client->request('GET', '/benutzer');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($portal->email, $page->text());
    }

    /** Und es bekommt kein einziges Recht aus dem Katalog. */
    public function testAPortalAccountHasNoPermissions(): void
    {
        $client = self::signedInForParty(self::aPartyId());

        // Ueber den Container statt ueber eine Seite: `is_granted()` haengt an
        // dieser Antwort, und sie soll fuer *jeden* Schluessel falsch sein,
        // nicht nur fuer die, die zufaellig auf einer Seite vorkommen.
        $client->request('GET', '/portal/daten');
        self::assertResponseIsSuccessful();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->forParty(self::aPartyId());
        self::assertNotNull($user);

        self::assertTrue($user->isPortalAccount());
        self::assertFalse($user->isAdministrator());
        self::assertSame(['ROLE_PORTAL'], $user->getRoles());
    }

    /**
     * Eine Rolle, die an der Anwendung vorbei in die Tabelle geschrieben wurde,
     * gibt einem Portalkonto trotzdem nichts.
     *
     * Der Fall, fuer den der zweite Riegel da ist. Ueber die Anwendung kann er
     * nicht eintreten — `assignRoles()` weist ein Portalkonto ab. Aber eine
     * Datenbank ueberlebt die Anwendung, die sie angelegt hat: ein Skript, eine
     * Migration, eine Hand am `psql`. Danach darf trotzdem nichts passieren.
     */
    public function testARoleSmuggledIntoTheTableGrantsNothing(): void
    {
        $client = self::signedInForParty(self::aPartyId());

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->forParty(self::aPartyId());
        self::assertNotNull($user);

        self::smuggleRolesTo($user->id());

        // Frisch geladen, damit die Zuordnung wirklich aus der Datenbank kommt.
        $again = $users->forParty(self::aPartyId());
        self::assertNotNull($again);
        self::assertFalse($again->isAdministrator(), 'Ein Portalkonto ist nie Administrator');

        // Und die Rechte selbst, nicht nur die Seite: ueber HTTP faellt schon
        // `ROLE_USER` vorher — das verdeckte den zweiten Riegel, statt ihn zu
        // pruefen.
        $effective = self::getContainer()->get(EffectivePermissions::class);
        self::assertInstanceOf(EffectivePermissions::class, $effective);
        self::assertTrue($effective->of($again)->isEmpty(), 'Ein Portalkonto hat kein einziges Recht');

        $client->request('GET', '/benutzer');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Wer Stammdaten nur lesen darf, schaltet niemanden frei.
     *
     * Die Karte steht dann gar nicht erst da — ein abgeschalteter Knopf waere
     * hier die schlechtere Antwort, denn es gibt nichts zu erklaeren: die
     * Freischaltung ist eine Bearbeitung.
     */
    public function testAReaderCannotGrantAccess(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);
        self::buildTheProperty();
        $reference = self::anOwner()->reference();

        $page = $client->request('GET', '/stammdaten/'.$reference.'?abschnitt=erreichbarkeit');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Portalzugang', $page->text());

        // Und der Weg dahinter ist ebenfalls zu — ein fehlender Knopf haelt
        // niemanden auf, der die Adresse kennt.
        $client->request('POST', '/stammdaten/'.$reference.'/portalzugang', ['email' => 'paula@example.org']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Nach der Anmeldung landet ein Portalkonto im Portal.
     *
     * Das Dashboard waere ein 403 — es verlangt `ROLE_USER`, und das hat es
     * nicht. Wer sich gerade erfolgreich angemeldet hat und eine
     * Zugriffsverweigerung sieht, glaubt, sein Konto sei kaputt.
     */
    public function testSignInLandsInThePortal(): void
    {
        $client = self::createClient();
        self::removeTestUser();
        self::aSignedUpPortalAccount();

        $page = $client->request('GET', '/login');
        $client->submit($page->selectButton('Anmelden')->form([
            '_username' => static::testEmail(),
            '_password' => self::PASSWORD,
        ]));

        self::assertResponseRedirects('/portal');
    }

    /**
     * Abmelden und die Sprache wechseln muss es koennen.
     *
     * Beides liegt ausserhalb von `^/portal` und faellt damit unter den
     * Auffang-Eintrag, der `ROLE_USER` verlangt. Ohne ausdrueckliche Ausnahme
     * saesse ein Portalnutzer in der falschen Sprache fest und kaeme nicht
     * wieder heraus.
     */
    public function testItCanStillSwitchLanguageAndSignOut(): void
    {
        $client = self::signedInForParty(self::aPartyId());

        $client->request('GET', '/locale/en');
        self::assertResponseRedirects();

        $crawler = $client->request('GET', '/portal/daten');
        $token = $crawler->filter('form[action="/logout"] input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/logout', ['_csrf_token' => (string) $token]);
        self::assertResponseRedirects('/login');

        $client->request('GET', '/portal/daten');
        self::assertResponseRedirects('/login', null, 'Wirklich abgemeldet');
    }

    /** Freischalten, erneut einladen, entziehen — der Weg aus den Stammdaten. */
    public function testAccessIsGrantedFromTheMasterData(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);
        self::buildTheProperty();
        $reference = self::anOwner()->reference();

        $page = $client->request('GET', '/stammdaten/'.$reference.'?abschnitt=erreichbarkeit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Portalzugang', $page->text());

        $client->request('POST', '/stammdaten/'.$reference.'/portalzugang', [
            '_token' => self::tokenIn($page, 'portalzugang'),
            'email' => 'paula@example.org',
        ]);

        $account = self::accounts()->forParty(self::anOwnerPartyId());
        self::assertNotNull($account);
        self::assertSame('paula@example.org', $account->email);
        self::assertFalse($account->isRevoked, 'Frisch eingeladen ist nicht entzogen');

        $again = $client->request('GET', '/stammdaten/'.$reference.'?abschnitt=erreichbarkeit');
        $client->request('POST', '/stammdaten/'.$reference.'/portalzugang/entziehen', [
            '_token' => self::tokenIn($again, 'portalzugang/entziehen'),
        ]);

        // Entzogen, nicht geloescht: wer geloescht wird, verschwindet aus den
        // Gespraechen, an denen er beteiligt war.
        $after = self::accounts()->forParty(self::anOwnerPartyId());
        self::assertNotNull($after, 'Der Zugang ist weg statt entzogen');
        self::assertTrue($after->isRevoked);
        self::assertFalse($after->canSignIn);
    }

    /**
     * Die Auswahl ist Eingabe, und Eingabe wird geprueft.
     *
     * Das Feld kommt aus einem `select`, aber eine abgeschickte Anfrage
     * kommt aus dem Netz: sie kann leer sein, unsinnig, oder eine Adresse
     * tragen, die schon zu einem anderen Konto gehoert. Aus jedem der drei
     * Faelle wird eine Meldung — und keine Fehlerseite.
     *
     * @param non-empty-string $expected
     */
    #[DataProvider('unusableAddresses')]
    public function testAnUnusableAddressIsAnswered(string $email, string $expected): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);
        self::buildTheProperty();
        $reference = self::anOwner()->reference();
        self::someoneElseHas('paula@example.org');

        $page = $client->request('GET', '/stammdaten/'.$reference.'?abschnitt=erreichbarkeit');
        $client->request('POST', '/stammdaten/'.$reference.'/portalzugang', [
            '_token' => self::tokenIn($page, 'portalzugang'),
            'email' => $email,
        ]);

        self::assertResponseRedirects();
        self::assertNull(self::accounts()->forParty(self::anOwnerPartyId()), 'Es ist doch ein Zugang entstanden.');
        self::assertStringContainsString($expected, $client->followRedirect()->text());
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function unusableAddresses(): iterable
    {
        yield 'leer' => ['', 'Bitte eine E-Mail-Adresse angeben'];
        yield 'keine Adresse' => ['nicht-at-example', 'keine gültige E-Mail-Adresse'];
        yield 'schon vergeben' => ['paula@example.org', 'bereits ein Konto'];
    }

    protected static function testEmail(): string
    {
        return 'portalzugang@example.org';
    }

    /** Ein fremdes Konto auf derselben Adresse — es gibt sie also schon. */
    private static function someoneElseHas(string $email): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $users->save(new User($users->nextNumber(), Email::fromString($email)));
    }

    /**
     * Das eingeladene Konto raeumt sich nicht selbst weg.
     *
     * Anders als das Testkonto entsteht es ueber den Anwendungsfall und traegt
     * darum eine feste Adresse. Bliebe es stehen, liefe der naechste Lauf in
     * den eindeutigen Index — und scheiterte an etwas, das mit ihm nichts zu
     * tun hat.
     */
    private static function removeThePortalAccount(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if ($entityManager instanceof EntityManagerInterface) {
            $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email IN (:mails)')
                ->setParameter('mails', ['portalmensch@example.org', 'paula@example.org'])
                ->execute();
        }
    }

    /**
     * Jede Adresse, die kein Portal ist und ohne Kennung aufgerufen werden kann.
     *
     * Adressen mit Platzhaltern bleiben draussen: sie brauchten eine gueltige
     * Kennung, und eine erfundene ergaebe 404 aus dem falschen Grund.
     *
     * @return array<string, string>
     */
    private static function internalRoutes(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $found = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            $methods = $route->getMethods();
            $public = str_starts_with($path, '/portal')
                || str_contains($path, '{')
                || ([] !== $methods && !\in_array('GET', $methods, true))
                || \in_array($name, self::PUBLIC_ROUTES, true)
                || str_starts_with($name, '_');

            if (!$public) {
                $found[$name] = $path;
            }
        }

        self::assertGreaterThan(20, \count($found), 'Die Routenliste ist verdächtig kurz');

        return $found;
    }

    /** Ein Portalkonto, das sich wirklich anmelden kann — mit bekanntem Passwort. */
    private static function aSignedUpPortalAccount(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User($users->nextNumber(), Email::fromString(static::testEmail()), self::aPartyId());
        $user->changePassword($hasher->hashPassword($user, self::PASSWORD));
        $user->activate();
        $users->save($user);
    }

    private static function aPortalAccountFor(string $partyId): PortalAccount
    {
        $invited = self::accounts()->inviteFor($partyId, 'portalmensch@example.org');

        return $invited['account'];
    }

    /**
     * Eine Parteikennung, ohne dafuer ein Objekt zu bauen.
     *
     * Die beiden Tests der Trennung brauchen nur *irgendeine* Partei: es geht
     * darum, dass das Konto fuer jemanden spricht, nicht fuer wen.
     */
    private static function aPartyId(): string
    {
        return '3f1d0b2e-5a4c-4c8e-9f77-2b6c8e1a0d44';
    }

    private static function accounts(): PortalAccounts
    {
        $accounts = self::getContainer()->get(PortalAccounts::class);
        self::assertInstanceOf(PortalAccounts::class, $accounts);

        return $accounts;
    }

    /**
     * Das Token **dieses** Formulars.
     *
     * Nicht das erste der Seite: auf der Stammdatenseite steht das Archivieren
     * weiter oben, und sein Token gilt fuer eine andere Kennung. Der Test
     * scheiterte dann an der Pruefung statt an der Sache.
     */
    private static function tokenIn(Crawler $crawler, string $action): string
    {
        $token = $crawler->filter('form[action$="/'.$action.'"] input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    private static function anOwnerPartyId(): string
    {
        return self::anOwner()->id();
    }

    /**
     * Was nur ein Skript tun koennte — und was danach folgenlos bleiben muss.
     *
     * **Drei Wege auf einmal**, weil die Auswertung drei hat: die Systemrolle
     * haengt an `isAdministrator()`, eine gewoehnliche Rolle mit einem Recht
     * an den Rollenzuweisungen, und ein direkt zugewiesenes Recht an keinem
     * von beiden. Jeder einzeln geprueft waere ein Test, der gruen bleibt,
     * waehrend die anderen beiden offenstehen.
     */
    private static function smuggleRolesTo(string $userId): void
    {
        $roles = self::getContainer()->get(RoleRepository::class);
        self::assertInstanceOf(RoleRepository::class, $roles);

        $name = RoleName::fromString('Geschmuggelt');
        $role = $roles->byName($name) ?? Role::named($name);
        $roles->save($role);

        $assignments = self::getContainer()->get(PermissionAssignments::class);
        self::assertInstanceOf(PermissionAssignments::class, $assignments);
        $assignments->setForRole($role->id(), GrantedPermissions::of([AuthPermissions::USERS_VIEW]));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        foreach ([$roles->system()->id(), $role->id()] as $roleId) {
            $entityManager->getConnection()->executeStatement(
                'INSERT INTO auth_user_role (user_id, role_id) VALUES (?, ?)',
                [$userId, $roleId],
            );
        }

        // Und ein direkt zugewiesenes Recht: es geht einen anderen Weg durch
        // die Auswertung als die aus Rollen, und es waere der einzige, der
        // ohne den Riegel in `of()` durchkaeme.
        $entityManager->getConnection()->executeStatement(
            'INSERT INTO auth_user_permission (user_id, permission_key) VALUES (?, ?)',
            [$userId, AuthPermissions::ROLES_VIEW],
        );

        $entityManager->clear();
    }

    private static function removeTheSmuggledRole(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if ($entityManager instanceof EntityManagerInterface) {
            $entityManager->createQuery('DELETE FROM '.Role::class.' r WHERE r.name = :name')
                ->setParameter('name', 'Geschmuggelt')
                ->execute();
        }
    }
}
