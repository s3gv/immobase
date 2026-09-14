<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DomainException;

/**
 * Eine freigegebene Abrechnung laesst sich nicht mehr aendern.
 *
 * Sie ist zugestellt worden. Was daran nicht stimmt, wird korrigiert und
 * nicht ueberschrieben — sonst stuende beim Empfaenger etwas anderes als bei
 * uns, und niemand koennte sagen, welches von beiden gilt.
 */
final class StatementIsReleased extends DomainException
{
    public static function already(): self
    {
        return new self('Diese Abrechnung ist freigegeben. Änderungen gehen nur noch über eine Korrektur.');
    }

    public static function andCannotBeDeleted(): self
    {
        return new self('Freigegebene Abrechnungen werden nicht gelöscht.');
    }
}
