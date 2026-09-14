<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte an den Stammdaten.
 *
 * Archivieren zaehlt zu „bearbeiten": es entfernt nichts, es raeumt aus dem
 * Weg. Nur das endgueltige Loeschen braucht das dritte Recht.
 */
final class PartyPermissions implements PermissionSource
{
    public const string VIEW = 'parties.view';
    public const string EDIT = 'parties.edit';
    public const string DELETE = 'parties.delete';

    public function permissions(): array
    {
        return [
            Permission::view('parties'),
            Permission::edit('parties'),
            Permission::delete('parties'),
        ];
    }
}
