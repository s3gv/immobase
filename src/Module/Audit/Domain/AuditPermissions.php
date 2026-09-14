<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Das Recht am Aenderungsprotokoll.
 *
 * Nur Lesen. Das Protokoll wird nicht bearbeitet — ein Protokoll, das sich
 * aendern laesst, beantwortet die Frage nicht mehr, fuer die es da ist. Und
 * geloescht wird es von selbst, nach achtundvierzig Stunden.
 */
final class AuditPermissions implements PermissionSource
{
    public const string VIEW = 'audit.view';

    public function permissions(): array
    {
        return [Permission::view('audit')];
    }
}
