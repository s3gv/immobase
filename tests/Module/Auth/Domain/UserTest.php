<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Domain;

use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserStatus;
use App\Shared\Contact\Email;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testStartsOutInvitedAndWithoutPassword(): void
    {
        $user = self::user();

        self::assertSame('erika@example.org', $user->email()->toString());
        self::assertSame(UserStatus::Invited, $user->status());
        self::assertFalse($user->hasPassword());
    }

    /**
     * Bis jemand seinen Namen eintraegt, ist die Adresse die einzige ehrliche
     * Bezeichnung. Wer einlaedt, kennt fremde Namen selten genau.
     */
    public function testShowsTheAddressUntilThereIsAName(): void
    {
        $user = self::user();

        self::assertSame('erika@example.org', $user->displayName());

        $user->nameYourself(PersonName::of('Erika', 'Muster', 'Verwaltung'));

        self::assertSame('Erika Muster', $user->displayName());
    }

    public function testAlwaysCarriesTheUserRole(): void
    {
        self::assertContains('ROLE_USER', self::user()->getRoles());
    }

    /**
     * Symfony kennt nur noch die Basisrolle.
     *
     * ROLE_ADMIN gab es, solange „darf alles" eine Spalte war. Was jemand
     * darf, beantwortet jetzt der PermissionVoter.
     */
    public function testSymfonyKnowsOnlyTheBaseRole(): void
    {
        $user = self::user();
        $user->assignRoles([Role::administrator()]);

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testTheSystemRoleMakesAnAdministrator(): void
    {
        $user = self::user();

        $user->assignRoles([Role::named(RoleName::fromString('Buchhaltung'))]);
        self::assertFalse($user->isAdministrator());

        $user->assignRoles([Role::named(RoleName::fromString('Buchhaltung')), Role::administrator()]);
        self::assertTrue($user->isAdministrator());
    }

    /**
     * Ein Konto ohne Rolle kann nichts und waere eine Sackgasse: es meldet
     * sich an und sieht eine leere Anwendung, ohne dass jemandem auffiele,
     * warum.
     */
    public function testAnAccountNeedsAtLeastOneRole(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::user()->assignRoles([]);
    }

    public function testUserIdentifierIsTheEmailAddress(): void
    {
        self::assertSame('erika@example.org', self::user()->getUserIdentifier());
    }

    public function testStoresHashedPassword(): void
    {
        $user = self::user();
        $user->changePassword('$2y$13$abcdefghijklmnopqrstuv');

        self::assertSame('$2y$13$abcdefghijklmnopqrstuv', $user->getPassword());
    }

    public function testEachUserGetsItsOwnIdentifier(): void
    {
        self::assertNotSame(self::user()->id(), self::user('zweite@example.org')->id());
    }

    /**
     * Ohne Passwort kommt niemand herein — auch nicht mit dem Zustand "aktiv".
     * Ein eingeladenes Konto *mit* Passwort dagegen schon: genau diese erste
     * Anmeldung macht es aktiv.
     */
    public function testSignInNeedsAPasswordAndAnUnblockedAccount(): void
    {
        $user = self::user();

        self::assertFalse($user->canSignIn(), 'Eingeladen, ohne Passwort');

        $user->changePassword('$2y$13$abcdefghijklmnopqrstuv');

        self::assertTrue($user->canSignIn(), 'Eingeladen, mit Passwort');

        $user->deactivate();

        self::assertFalse($user->canSignIn(), 'Deaktiviert');
    }

    public function testTheFirstSignInActivatesTheAccount(): void
    {
        $user = self::user();
        $moment = new DateTimeImmutable('2026-09-09 10:00:00');

        $user->signedInAt($moment);

        self::assertSame(UserStatus::Active, $user->status());
        self::assertEquals($moment, $user->lastSignInAt());
    }

    /**
     * Wer nie ein Passwort gesetzt hat, geht beim Reaktivieren zurueck nach
     * "eingeladen". "Aktiv" ohne Passwort verspraeche etwas, das die
     * Anmeldung nicht halten kann.
     */
    public function testReactivatingWithoutAPasswordReturnsToInvited(): void
    {
        $user = self::user();
        $user->deactivate();
        $user->reactivate();

        self::assertSame(UserStatus::Invited, $user->status());

        $user->changePassword('$2y$13$abcdefghijklmnopqrstuv');
        $user->deactivate();
        $user->reactivate();

        self::assertSame(UserStatus::Active, $user->status());
    }

    private static function user(string $email = 'erika@example.org'): User
    {
        return new User(1001, Email::fromString($email));
    }
}
