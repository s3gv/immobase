<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Ui\Page;

interface StatementRepository
{
    public function save(Statement $statement): void;

    /** Nur Entwuerfe — auf einem freigegebenen Lauf ist Post gebaut. */
    public function remove(Statement $statement): void;

    public function byId(string $id): ?Statement;

    /** Die naechste sichtbare Nummer, aus der Sequenz. */
    public function nextNumber(): int;

    public function countMatching(StatementFilter $filter): int;

    /**
     * @return list<Statement> die neuesten zuerst
     */
    public function matching(StatementFilter $filter, Page $page): array;

    /**
     * Die Wirtschaftsjahre, zu denen es Laeufe gibt — die juengsten zuerst.
     *
     * Fuer das Auswahlfeld der Liste: angeboten wird, was es gibt. Eine feste
     * Spanne boete Jahre an, die nichts finden.
     *
     * @return list<int>
     */
    public function years(): array;

    /**
     * @return list<Statement> alle freigegebenen, fuer die Korrekturpruefung
     */
    public function released(): array;

    /**
     * Die Quellen eines Laufs.
     *
     * @return list<StatementSource>
     */
    public function sourcesOf(string $statementId): array;

    /**
     * @param list<StatementSource> $sources
     */
    public function replaceSources(string $statementId, array $sources): void;

    /**
     * Alle Iterationen einer Abrechnung, das Original zuerst.
     *
     * @return list<Statement>
     */
    public function iterationsOf(int $number): array;

    /**
     * Wer kein PDF bekommen soll — nur die Ausnahmen.
     *
     * @return list<string> Empfaengerschluessel
     */
    public function withoutPdf(string $statementId): array;

    /**
     * @param list<string> $recipientKeys
     */
    public function keepWithoutPdf(string $statementId, array $recipientKeys): void;

    /**
     * Welche dieser Quellen von einer Abrechnung benutzt werden.
     *
     * @param list<string> $sourceIds
     *
     * @return array<string, string> Quelle auf die Referenz, die sie haelt
     */
    public function holders(array $sourceIds): array;
}
