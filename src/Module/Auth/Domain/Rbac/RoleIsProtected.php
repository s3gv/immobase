<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

use RuntimeException;

/**
 * Jemand wollte die Systemrolle aendern.
 *
 * Ueber die Oberflaeche ist das nicht zu erreichen — dort steht sie ohne
 * Aktionen da. Die Ausnahme deckt den Weg ueber ein nachgebautes Formular ab.
 */
final class RoleIsProtected extends RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(\sprintf('Die Systemrolle "%s" laesst sich nicht aendern.', $name));
    }
}
