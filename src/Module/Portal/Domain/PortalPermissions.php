<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte an den Anfragen.
 *
 * Benannt nach dem, was in der Navigation steht, und nicht nach dem Modul:
 * „Portal" ist fuer den Verwalter kein Ort, an den er geht — er geht zu den
 * Anfragen.
 *
 * **Kein Recht fuer den Portalzugang selbst.** Ein Portalkonto steht in
 * keinem Katalog und traegt keine Rolle; es ist daran zu erkennen, dass es
 * fuer eine Partei spricht. Ein Recht dafuer stuende in „Rollen und Rechte"
 * und liesse sich einem Mitarbeiter mitgeben.
 *
 * **Kein Loeschrecht.** Ein Gespraech gehoert beiden Seiten; es verschwindet
 * mit dem Stammdatensatz und sonst nicht.
 */
final class PortalPermissions implements PermissionSource
{
    public const string VIEW = 'enquiries.view';
    public const string EDIT = 'enquiries.edit';

    public function permissions(): array
    {
        return [
            Permission::view('enquiries'),
            Permission::edit('enquiries'),
        ];
    }
}
