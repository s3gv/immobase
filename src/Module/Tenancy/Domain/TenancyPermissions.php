<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte an den Mietverhaeltnissen.
 *
 * Deaktivieren laeuft unter Bearbeiten und nicht unter Loeschen: es entfernt
 * nichts, es beendet. Wer ein Mietverhaeltnis pflegen darf, darf es auch
 * beenden — alles andere waere eine Grenze, die im Alltag niemand zieht.
 */
final class TenancyPermissions implements PermissionSource
{
    public const string VIEW = 'tenancies.view';
    public const string EDIT = 'tenancies.edit';
    public const string DELETE = 'tenancies.delete';

    public function permissions(): array
    {
        return [
            Permission::view('tenancies'),
            Permission::edit('tenancies'),
            Permission::delete('tenancies'),
        ];
    }
}
