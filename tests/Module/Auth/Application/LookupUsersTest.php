<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Application;

use App\Module\Auth\Application\LookupUsers;
use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use App\Tests\Module\Auth\Fixture\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class LookupUsersTest extends TestCase
{
    public function testReturnsContractViewOfAnExistingUser(): void
    {
        $user = new User(1001, Email::fromString('erika@example.org'));
        $user->nameYourself(PersonName::of('Erika', 'Muster', 'Verwaltung'));
        $lookup = new LookupUsers($this->repositoryReturning($user));

        $found = $lookup->byEmail('erika@example.org');

        self::assertNotNull($found);
        self::assertSame(1001, $found->number);
        self::assertSame('erika@example.org', $found->email);
        self::assertSame('Erika Muster', $found->displayName);
        self::assertSame('Verwaltung', $found->jobTitle);
        self::assertFalse($found->isAdministrator);
        self::assertFalse($found->isActive, 'Eingeladen ist noch nicht aktiv');
    }

    public function testReportsAdministratorStatus(): void
    {
        $user = new User(1001, Email::fromString('erika@example.org'));
        $user->assignRoles([Role::administrator()]);
        $lookup = new LookupUsers($this->repositoryReturning($user));

        $found = $lookup->byEmail('erika@example.org');

        self::assertNotNull($found);
        self::assertTrue($found->isAdministrator);
    }

    public function testReturnsNullForUnknownAddress(): void
    {
        $lookup = new LookupUsers($this->repositoryReturning(null));

        self::assertNull($lookup->byEmail('nobody@example.org'));
    }

    public function testReturnsNullForMalformedAddressInsteadOfThrowing(): void
    {
        $lookup = new LookupUsers($this->repositoryReturning(null));

        self::assertNull($lookup->byEmail('not-an-address'));
    }

    private function repositoryReturning(?User $user): UserRepository
    {
        return null === $user ? new InMemoryUserRepository() : new InMemoryUserRepository($user);
    }
}
