<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Domain;

use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleIsProtected;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\SystemRole;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Die Rolle und die eine, die geschuetzt ist.
 *
 * „Administrator" haengt am Kennzeichen und nicht am Namen: waere der Name
 * das Merkmal, ergaebe eine Umbenennung eine Installation ohne Systemrolle.
 */
final class RoleTest extends TestCase
{
    public function testTheSystemRoleCannotBeRenamed(): void
    {
        $this->expectException(RoleIsProtected::class);

        Role::administrator()->rename(RoleName::fromString('Chef'));
    }

    public function testAnOrdinaryRoleCanBeRenamed(): void
    {
        $role = Role::named(RoleName::fromString('Buchhaltung'));
        $role->rename(RoleName::fromString('Rechnungswesen'));

        self::assertSame('Rechnungswesen', $role->name()->toString());
        self::assertFalse($role->isSystem());
    }

    public function testTheSystemRoleIsMarkedAndNamed(): void
    {
        $role = Role::administrator();

        self::assertTrue($role->isSystem());
        self::assertSame(SystemRole::NAME, $role->name()->toString());
    }

    /**
     * „Buchhaltung" und „buchhaltung" waeren fuer die Datenbank zwei Rollen
     * und fuer jeden Menschen davor eine.
     */
    public function testNamesAreComparedWithoutCase(): void
    {
        self::assertTrue(RoleName::fromString('Buchhaltung')->equals(RoleName::fromString('buchhaltung')));
        self::assertFalse(RoleName::fromString('Buchhaltung')->equals(RoleName::fromString('Verwaltung')));
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleName::fromString('   ');
    }

    public function testAnOverlongNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleName::fromString(str_repeat('a', RoleName::MAX_LENGTH + 1));
    }
}
