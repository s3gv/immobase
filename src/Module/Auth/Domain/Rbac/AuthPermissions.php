<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Was das Auth-Modul an Rechten kennt: Benutzer und Rollen.
 *
 * Zwei Bereiche aus einer Klasse, weil beide in diesem Modul liegen. Getrennt
 * sind sie in eine Richtung: `roles.*` allein verwaltet Rollen und Matrix,
 * ohne an fremde Konten zu kommen. So laesst sich die Rechteverwaltung
 * weiterreichen, ohne jemanden zum Administrator zu machen — die Matrix
 * vergibt nur Rechte, die der Handelnde selbst hat (siehe AssignPermissions).
 *
 * In die andere Richtung ist die Trennung ausdruecklich keine. `users.edit`
 * vergibt Rollen und Zusatzrechte und laedt Konten ein — wer es hat, kann sich
 * ueber ein zweites Konto jedes andere Recht verschaffen, `roles.edit`
 * eingeschlossen. Das ist so entschieden und nicht uebersehen: eine Sperre
 * dagegen waere nur scheinbar eine, solange dieselbe Person Konten anlegen
 * darf. Deshalb sagt es auch die Erklaerung zu „Benutzer verwalten", die in
 * der Matrix und unter „Meine Rechte" steht.
 *
 * „Mein Konto" ist absichtlich kein Bereich. Sein eigenes Konto einzurichten
 * ist keine Berechtigung, die jemand vergeben oder entziehen koennte; ein
 * frisch eingeladenes Konto braucht es, bevor es irgendein Recht hat.
 */
final class AuthPermissions implements PermissionSource
{
    public const string USERS_VIEW = 'users.view';
    public const string USERS_EDIT = 'users.edit';
    public const string USERS_DELETE = 'users.delete';

    public const string ROLES_VIEW = 'roles.view';
    public const string ROLES_EDIT = 'roles.edit';
    public const string ROLES_DELETE = 'roles.delete';

    public function permissions(): array
    {
        return [
            Permission::view('users'),
            Permission::edit('users'),
            Permission::delete('users'),
            Permission::view('roles'),
            Permission::edit('roles'),
            Permission::delete('roles'),
        ];
    }
}
