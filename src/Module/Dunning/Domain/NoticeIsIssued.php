<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use RuntimeException;

/**
 * Ein ausgestelltes Schreiben ist hinaus.
 *
 * Es liegt beim Schuldner, die Frist laeuft, und was daran falsch war, wird
 * nicht darin geaendert — dafuer gibt es die naechste Stufe oder ein
 * Schreiben ausserhalb der Anwendung.
 */
final class NoticeIsIssued extends RuntimeException
{
    public static function already(): self
    {
        return new self('dunning.error.notice_issued');
    }
}
