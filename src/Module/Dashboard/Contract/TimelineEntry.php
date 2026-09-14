<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Contract;

use DateTimeImmutable;

/**
 * Ein Termin auf dem Zeitstrahl.
 *
 * Vier Angaben, und in dieser Reihenfolge stehen sie auch in der Liste: das
 * Datum, darunter die Uhrzeit, daneben der Betreff und darunter, was er
 * betrifft. Mehr traegt eine Zeile nicht, ohne unlesbar zu werden.
 */
final readonly class TimelineEntry
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $at,
        public string $subject,
        /** Was es betrifft — bei einer Erinnerung die Notiz. Leer ist erlaubt. */
        public string $about,
        /** Vergangenes wird still dargestellt: es ist eine Auskunft, keine Aufforderung. */
        public bool $isPast,
    ) {
    }
}
