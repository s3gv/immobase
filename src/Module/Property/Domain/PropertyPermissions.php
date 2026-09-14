<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte an den Objekten.
 *
 * Einheiten laufen mit: sie sind Teil eines Objekts und kein eigener Bereich.
 * Wer die Objekte pflegen darf, pflegt auch ihre Einheiten — alles andere
 * waere eine Grenze, die im Alltag niemand zieht.
 */
final class PropertyPermissions implements PermissionSource
{
    public const string VIEW = 'properties.view';
    public const string EDIT = 'properties.edit';
    public const string DELETE = 'properties.delete';

    public function permissions(): array
    {
        return [
            Permission::view('properties'),
            Permission::edit('properties'),
            Permission::delete('properties'),
        ];
    }
}
