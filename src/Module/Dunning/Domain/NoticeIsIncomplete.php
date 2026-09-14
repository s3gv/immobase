<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use RuntimeException;

/**
 * Was fehlt, haelt die Ausstellung auf.
 *
 * Vor allem der Basiszinssatz: fehlt er fuer das laufende Halbjahr, rechnete
 * die Anwendung mit einem veralteten Satz weiter, und das fiele erst vor
 * Gericht auf. Die Luecken stehen benannt auf dem letzten Schritt.
 */
final class NoticeIsIncomplete extends RuntimeException
{
    public static function somethingIsMissing(): self
    {
        return new self('dunning.error.notice_incomplete');
    }
}
