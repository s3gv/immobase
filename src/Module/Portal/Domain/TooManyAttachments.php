<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use RuntimeException;

/**
 * Zu viele Dateien oder eine zu grosse.
 *
 * Die Grenzen sagen deutlicher als jeder Hilfetext, worum es hier geht: um
 * Belege und Abrechnungen, nicht um Videos.
 */
final class TooManyAttachments extends RuntimeException
{
    public static function tooMany(): self
    {
        return new self('portal.error.too_many_files');
    }

    public static function tooLarge(): self
    {
        return new self('portal.error.file_too_large');
    }
}
