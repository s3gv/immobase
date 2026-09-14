<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

use RuntimeException;

/**
 * Eine Rolle sollte geloescht werden, an der noch Konten haengen.
 *
 * Der Knopf ist in diesem Fall abgeschaltet; die Ausnahme faengt den Fall ab,
 * dass zwischen dem Zeichnen der Seite und dem Klick jemand die Rolle
 * vergeben hat.
 */
final class RoleStillInUse extends RuntimeException
{
    public function __construct(public readonly int $users)
    {
        parent::__construct(\sprintf('Die Rolle ist noch %d Konten zugeordnet.', $users));
    }
}
