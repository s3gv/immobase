<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

/**
 * Ein Eigentuemer einer Einheit und sein Anteil daran.
 *
 * Der Anteil ist der, den diese Partei von der Einheit haelt — nicht der
 * Miteigentumsanteil der Einheit am Objekt. Ein Ehepaar zu je der Haelfte
 * haelt zusammen einen Anteil von 1.
 */
final readonly class OwnerShare
{
    public function __construct(
        public string $partyId,
        public string $mea,
    ) {
    }
}
