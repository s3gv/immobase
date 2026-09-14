<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

use DateTimeImmutable;

/**
 * Wie viele Menschen ohne Mietvertrag in einer Einheit lebten.
 *
 * Gefragt wird nach einem Zeitraum, nicht nach heute: abgerechnet wird ein
 * Jahr, das vorbei ist. Die Abschnitte sind bereits auf den Zeitraum
 * beschnitten — wer ab Maerz zu dritt wohnt, kommt hier mit dem 1. Maerz an.
 */
interface UnitHouseholds
{
    /**
     * @param list<string> $unitIds
     *
     * @return array<string, list<HouseholdWindow>> Kennung der Einheit auf ihre Abschnitte, zeitlich sortiert
     */
    public function inPeriod(array $unitIds, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
