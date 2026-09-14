<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Domain;

use App\Shared\Ui\Page;
use DateTimeImmutable;

interface AuditRepository
{
    /**
     * Mehrere Zeilen auf einmal — ein Speichervorgang, viele Eintraege.
     *
     * @param list<AuditEntry> $entries
     */
    public function append(array $entries): void;

    public function countMatching(AuditFilter $filter): int;

    /**
     * @return list<AuditEntry> die juengsten zuerst
     */
    public function matching(AuditFilter $filter, Page $page): array;

    /**
     * Alles, was noch da ist — fuer den Ausdruck.
     *
     * Ohne Seite: der Ausdruck ist der Abzug der achtundvierzig Stunden, und
     * ein Abzug mit einer zweiten Seite anderswo waere keiner.
     *
     * @return list<AuditEntry>
     */
    public function all(): array;

    /**
     * Was aelter ist, faellt weg.
     *
     * @return int wie viele Zeilen gegangen sind
     */
    public function forgetBefore(DateTimeImmutable $day): int;
}
