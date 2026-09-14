<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

/**
 * Die Rolle „Administrator" und was sie ausmacht.
 *
 * Sie hat immer alle Rechte — auch die, die es heute noch nicht gibt. Deshalb
 * steht sie nicht in `auth_role_permission`: eine dort abgelegte Liste waere
 * bei der naechsten neuen Permission veraltet, und niemand koennte sie
 * nachtragen, ohne die Rolle zu bearbeiten, die man nicht bearbeiten kann.
 *
 * Der Pruefer fragt sie vor allen Tabellen ab. Waere eine Zuordnung kaputt,
 * kaeme der Administrator trotzdem herein und koennte es richten.
 */
final class SystemRole
{
    public const string NAME = 'Administrator';

    /**
     * Der Name, unter dem die Konten einer frischen Installation landen, die
     * nicht Administrator sind — siehe Migration.
     */
    public const string FALLBACK_NAME = 'Mitarbeiter';

    private function __construct()
    {
    }
}
