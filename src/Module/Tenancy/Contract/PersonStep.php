<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use DateTimeImmutable;

/**
 * Wie viele Menschen in einem Zeitabschnitt in der Einheit gewohnt haben.
 *
 * Fuer den Verteilerschluessel nach Personen. Wechselt die Zahl mitten im
 * Jahr, entstehen mehrere Abschnitte — wer nach Personen verteilt, verteilt
 * dann tagegewichtet und nicht nach dem Stand am Silvesterabend.
 */
final readonly class PersonStep
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $people,
    ) {
    }
}
