<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Contract;

use App\Module\Audit\Domain\AuditEntry;

/**
 * Eine Zeile des Protokolls, fertig zum Anzeigen — mit dem Weg dorthin, wenn
 * es einen gibt.
 *
 * Leer ist der Regelfall und kein Mangel: was geloescht wurde, fuehrt
 * nirgendwohin, und gerade dafuer steht die Kennung daneben.
 */
final readonly class AuditLine
{
    public function __construct(
        public AuditEntry $entry,
        public string $url = '',
    ) {
    }
}
