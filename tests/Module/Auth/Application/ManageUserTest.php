<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Application;

use App\Module\Auth\Application\ManageUser;
use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Application\Rbac\LastUserManager;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserStatus;
use App\Tests\Module\Auth\Fixture\Accounts;
use App\Tests\Module\Auth\Fixture\InMemoryPermissionAssignments;
use App\Tests\Module\Auth\Fixture\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Die Sperren, die der Controller abfragt, bevor er irgendetwas tut.
 *
 * Sie stehen hier und nicht im Controller, weil dieselbe Frage an drei Stellen
 * gebraucht wird: beim Zeichnen der Liste, auf der Detailseite und beim
 * Ausfuehren. Ein abgeschalteter Knopf haelt niemanden auf, der das Formular
 * nachbaut — deshalb muss die Antwort dieselbe sein.
 */
final class ManageUserTest extends TestCase
{
    public function testNobodyMayTouchTheirOwnAccount(): void
    {
        $me = self::administrator('ich@example.org');
        $colleague = self::administrator('du@example.org');
        $manage = self::manage($me, $colleague);

        self::assertSame('user.protected.self', $manage->reasonAgainstTouching($me, $me->id()));
        self::assertFalse($manage->mayTouch($me, $me->id()));
    }

    public function testTheLastUserManagerIsProtectedFromEveryone(): void
    {
        $admin = self::administrator('admin@example.org');
        $someoneElse = self::member('mieter@example.org');
        $manage = self::manage($admin, $someoneElse);

        self::assertSame(
            'user.protected.last_manager',
            $manage->reasonAgainstTouching($admin, $someoneElse->id()),
            'Auch ein anderer darf ihn nicht abschalten',
        );
    }

    public function testAnOrdinaryAccountMayBeTouched(): void
    {
        $admin = self::administrator('admin@example.org');
        $second = self::administrator('zweite@example.org');
        $member = self::member('mieter@example.org');

        self::assertTrue(self::manage($admin, $second, $member)->mayTouch($member, $admin->id()));
    }

    public function testDeactivatingAndBack(): void
    {
        $member = self::member('mieter@example.org');
        $manage = self::manage($member);

        self::assertSame('user.deactivated', $manage->toggleActivation($member, 'wer-anders')->message);
        self::assertSame(UserStatus::Deactivated, $member->status());

        self::assertSame('user.reactivated', $manage->toggleActivation($member, 'wer-anders')->message);
        self::assertSame(UserStatus::Active, $member->status());
    }

    /**
     * Die Sperre gilt auch beim Ausfuehren, nicht nur beim Zeichnen.
     *
     * Ein abgeschalteter Knopf haelt niemanden auf, der das Formular
     * nachbaut — die Aenderung muss von selbst ablehnen.
     */
    public function testAGuardedChangeRefusesInsteadOfRunning(): void
    {
        $admin = self::administrator('admin@example.org');
        $someoneElse = self::member('mieter@example.org');
        $manage = self::manage($admin, $someoneElse);

        $outcome = $manage->toggleActivation($admin, $someoneElse->id());

        self::assertFalse($outcome->applied);
        self::assertSame('user.protected.last_manager', $outcome->message);
        self::assertSame(UserStatus::Active, $admin->status(), 'Und hat nichts angefasst');
    }

    private static function manage(User ...$users): ManageUser
    {
        $effective = new EffectivePermissions(new InMemoryPermissionAssignments(), Accounts::catalogue());
        $repository = (new InMemoryUserRepository(...$users))->knowing($effective);

        return new ManageUser($repository, new LastUserManager($repository));
    }

    private static function administrator(string $email): User
    {
        return Accounts::administrator($email);
    }

    private static function member(string $email): User
    {
        return Accounts::member($email);
    }
}
