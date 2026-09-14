<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use DateTimeImmutable;

/**
 * Wer wann in welcher Einheit gewohnt hat.
 *
 * Eigene Flaeche neben {@see TenancyDirectory}: dort geht es um die
 * Vorauszahlung von heute, hier um einen ganzen Zeitraum in der
 * Vergangenheit. Eine Abrechnung fragt nach einem Jahr und bekommt jeden
 * Abschnitt, der es beruehrt hat — auch die, die laengst beendet sind.
 *
 * Entwuerfe kommen nicht mit. Ein Mietverhaeltnis, das nie aktiv war, hat
 * niemandem etwas berechnet.
 */
interface TenancySpans
{
    /**
     * @param list<string> $unitIds
     *
     * @return array<string, list<TenancySpan>> Kennung der Einheit auf ihre Abschnitte, zeitlich sortiert
     */
    public function inPeriod(array $unitIds, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
