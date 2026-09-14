<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Legt fuer einen Test ein angemeldetes Konto an und raeumt es wieder weg.
 *
 * Fast alles liegt hinter der Anmeldung; ohne angemeldeten Client antwortet
 * die Firewall und der Controller kaeme nie zum Zug. Das Konto muss dafuer
 * wirklich existieren: der Benutzer-Provider laedt es bei jedem Aufruf neu,
 * und ein nur erdachtes Konto floege sofort raus.
 *
 * Jeder Test bringt seine eigene Adresse mit, damit parallel laufende Tests
 * einander nicht das Konto unter den Fuessen wegloeschen — und mit ihr eine
 * eigene Rolle, damit sie sich auch nicht die Rechte umstellen.
 */
trait SignsIn
{
    abstract protected static function testEmail(): string;

    /**
     * @param array<string, mixed> $server
     */
    protected static function signedInAs(array $server = [], bool $asAdministrator = false): KernelBrowser
    {
        return self::signIn($server, $asAdministrator ? null : []);
    }

    /**
     * Ein Konto mit genau diesen Rechten — und ohne Systemrolle.
     *
     * Der interessante Fall: die Anwendung muss auch fuer jemanden stimmen,
     * der nicht alles darf.
     *
     * @param list<string>         $permissions
     * @param array<string, mixed> $server
     */
    protected static function signedInWith(array $permissions, array $server = []): KernelBrowser
    {
        return self::signIn($server, $permissions);
    }

    /**
     * Ein Portalkonto — fuer eine Partei und ohne jede Rolle.
     *
     * Ausdruecklich kein Sonderweg im Test: es entsteht genauso, wie es die
     * Anwendung anlegt, damit der Test auch wirklich das prueft, was spaeter
     * herauskommt.
     *
     * @param array<string, mixed> $server
     */
    protected static function signedInForParty(string $partyId, array $server = []): KernelBrowser
    {
        $client = self::createClient([], $server);
        self::signInForParty($client, $partyId);

        return $client;
    }

    /**
     * Dasselbe, aber an einem Client, den es schon gibt.
     *
     * Gebraucht, wo die Partei erst nach dem Start entsteht: die Vorlage baut
     * das Objekt samt Eigentuemer, und den Kernel darf man nur einmal
     * hochfahren. Erst Client, dann Stammdaten, dann anmelden.
     */
    protected static function signInForParty(KernelBrowser $client, string $partyId): void
    {
        self::removeTestUser();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = new User($users->nextNumber(), Email::fromString(static::testEmail()), $partyId);
        $user->nameYourself(PersonName::of('Test', 'Portal'));
        $user->changePassword('$2y$13$ojDeCXcOJLQF4YQfBBRcuOqBu4jRDlNQCEnbHTIRJRQ6MgxdvwGmi');
        $user->activate();

        $users->save($user);
        $client->loginUser($user);
    }

    /**
     * @param array<string, mixed> $server
     * @param list<string>|null    $permissions null bedeutet: die Systemrolle
     */
    private static function signIn(array $server, ?array $permissions): KernelBrowser
    {
        $client = self::createClient([], $server);

        self::removeTestUser();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        // Die Nummer kommt aus der Sequenz und nicht aus dem Zufall: sie ist
        // eindeutig, und eine geratene Zahl trifft frueher oder spaeter eine
        // schon vergebene. Der Test, dem das passiert, scheitert dann an
        // etwas, das mit ihm nichts zu tun hat — und beim naechsten Lauf
        // faellt eine andere Zahl, also ist es auch nicht nachstellbar.
        $user = new User($users->nextNumber(), Email::fromString(static::testEmail()));
        $user->nameYourself(PersonName::of('Test', 'Konto'));
        // Ein Testkonto muss sich anmelden koennen: dafuer braucht es ein
        // Passwort und einen Zustand, den der UserChecker durchlaesst.
        $user->changePassword('$2y$13$ojDeCXcOJLQF4YQfBBRcuOqBu4jRDlNQCEnbHTIRJRQ6MgxdvwGmi');
        $user->activate();
        $user->assignRoles([self::roleFor($permissions)]);

        $users->save($user);
        $client->loginUser($user);

        return $client;
    }

    /**
     * @param list<string>|null $permissions
     */
    private static function roleFor(?array $permissions): Role
    {
        $roles = self::getContainer()->get(RoleRepository::class);
        self::assertInstanceOf(RoleRepository::class, $roles);

        if (null === $permissions) {
            return $roles->system();
        }

        $name = RoleName::fromString('Test '.static::testEmail());
        $role = $roles->byName($name) ?? Role::named($name);
        $roles->save($role);

        $assignments = self::getContainer()->get(PermissionAssignments::class);
        self::assertInstanceOf(PermissionAssignments::class, $assignments);
        $assignments->setForRole($role->id(), GrantedPermissions::of($permissions));

        return $role;
    }

    private static function removeTestUser(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', static::testEmail())
            ->execute();

        // Auch die eigene Rolle: sonst sammeln sich in der Testdatenbank mit
        // jedem Lauf Rollen an, und die Rollenuebersicht faende sie alle.
        $entityManager->createQuery('DELETE FROM '.Role::class.' r WHERE r.name = :name')
            ->setParameter('name', 'Test '.static::testEmail())
            ->execute();
    }
}
