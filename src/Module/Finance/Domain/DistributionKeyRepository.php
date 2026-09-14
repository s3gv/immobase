<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Zugriff auf die Verteilerschluessel.
 */
interface DistributionKeyRepository
{
    public function save(DistributionKey $key): void;

    public function remove(DistributionKey $key): void;

    public function byId(string $id): ?DistributionKey;

    /**
     * Die Systemschluessel und die eigenen dieses Objekts.
     *
     * Genau das ist die Auswahl an einer Kostenposition: was ueberall gilt,
     * und was zu diesem Haus gehoert.
     *
     * @return list<DistributionKey>
     */
    public function forProperty(?string $propertyId): array;

    /**
     * Alle Schluessel, Systemschluessel zuerst — fuer die Pflegeseite.
     *
     * @return list<DistributionKey>
     */
    public function all(): array;
}
