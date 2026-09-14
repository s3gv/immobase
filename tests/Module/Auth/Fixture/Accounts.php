<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Fixture;

use App\Module\Auth\Application\Rbac\PermissionCatalogue;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\User;
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Settings\Contract\SettingsPermissions;
use App\Shared\Contact\Email;

/**
 * Konten, Rollen und der echte Rechtekatalog fuer Tests ohne Datenbank.
 *
 * Der Katalog wird aus denselben Klassen gebaut, die auch im Betrieb getaggt
 * sind. Eine erfundene Liste waere bequemer und wuerde genau die Faelle
 * verfehlen, um die es geht.
 */
final class Accounts
{
    private function __construct()
    {
    }

    public static function catalogue(): PermissionCatalogue
    {
        return new PermissionCatalogue([
            new AuthPermissions(),
            new PartyPermissions(),
            new SettingsPermissions(),
        ]);
    }

    public static function role(string $name): Role
    {
        return Role::named(RoleName::fromString($name));
    }

    public static function administrator(string $email): User
    {
        return self::member($email, Role::administrator());
    }

    public static function member(string $email, Role ...$roles): User
    {
        $user = new User(1001, Email::fromString($email));
        $user->changePassword('$2y$13$abcdefghijklmnopqrstuv');
        $user->activate();
        $user->assignRoles([] === $roles ? [self::role('Mitarbeiter')] : array_values($roles));

        return $user;
    }
}
