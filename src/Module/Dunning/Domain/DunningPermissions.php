<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte am Mahnwesen.
 *
 * Getrennt von den Finanzen, obwohl die Seite dort haengt: wer Zahlungen
 * erfasst, muss nicht mahnen duerfen. Eine Mahnung ist eine Rechtsfolge, und
 * wer sie ausloest, soll das ausdruecklich duerfen.
 *
 * **Kein Loeschrecht.** Ein Entwurf loescht, wer bearbeiten darf; ein
 * ausgestelltes Schreiben liegt beim Schuldner und verschwindet nicht mehr,
 * weil jemand es im System entfernt.
 */
final class DunningPermissions implements PermissionSource
{
    public const string VIEW = 'dunning.view';
    public const string EDIT = 'dunning.edit';

    public function permissions(): array
    {
        return [
            Permission::view('dunning'),
            Permission::edit('dunning'),
        ];
    }
}
