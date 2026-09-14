<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Zugriff auf die Kostenarten.
 *
 * Die Schnittstelle liegt in Domain, die Doctrine-Umsetzung in
 * Infrastructure. Kein anderes Modul darf beides benutzen — dafuer gibt es
 * Contract.
 */
interface CostKindRepository
{
    public function save(CostKind $kind): void;

    public function remove(CostKind $kind): void;

    public function byId(string $id): ?CostKind;

    /**
     * Alle, in der gewohnten Reihenfolge der BetrKV.
     *
     * @return list<CostKind>
     */
    public function all(): array;
}
