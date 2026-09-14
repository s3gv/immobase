<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

use DateTimeImmutable;

/** Was die Spiegelung vom Speicher braucht — im Betrieb {@see Store}. */
interface MirrorStorage
{
    /**
     * Die Arbeit laeuft allein: wer sie zugleich anstoesst, wartet.
     *
     * @param callable(): bool $work
     *
     * @return bool was die Arbeit zurueckgab
     */
    public function exclusively(callable $work): bool;

    public function lastSync(string $name): ?DateTimeImmutable;

    /**
     * @param array<string, list<array<string, scalar|null>>> $tables Tabellenname zu Zeilen
     */
    public function replaceAll(array $tables, DateTimeImmutable $at): void;
}
