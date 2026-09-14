<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use DateTimeImmutable;

interface RecoveryCodeRepository
{
    /**
     * Ersetzt alle Codes eines Kontos durch neue.
     *
     * Immer alle: eine Mischung aus alten und neuen Codes waere ein Bestand,
     * von dem niemand mehr weiss, welcher Zettel gilt.
     *
     * @param list<RecoveryCode> $codes
     */
    public function replaceAll(string $userId, array $codes): void;

    public function findUnused(string $userId, string $text): ?RecoveryCode;

    /**
     * Entwertet einen Code und sagt, ob dieser Aufruf ihn erwischt hat —
     * gepruefte und entwertete Verwendung in einer bedingten Abfrage.
     */
    public function consume(RecoveryCode $code, DateTimeImmutable $now): bool;

    public function countUnused(string $userId): int;

    public function removeAll(string $userId): void;
}
