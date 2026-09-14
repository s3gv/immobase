<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte an den Finanzen.
 *
 * Kostenarten und Verteilerschluessel laufen unter denselben Rechten wie die
 * Positionen: sie sind Teil derselben Arbeit, und eine eigene Grenze dafuer
 * zieht im Alltag niemand.
 */
final class FinancePermissions implements PermissionSource
{
    public const string VIEW = 'finance.view';
    public const string EDIT = 'finance.edit';
    public const string DELETE = 'finance.delete';

    public function permissions(): array
    {
        return [
            Permission::view('finance'),
            Permission::edit('finance'),
            Permission::delete('finance'),
        ];
    }
}
