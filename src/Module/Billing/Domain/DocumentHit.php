<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Ein Schreiben, wie die Suche es findet.
 *
 * Nur so viel, wie die Trefferzeile braucht: was es ist, wie es heisst, zu
 * welchem Objekt und Jahr es gehoert. Die Entitaet dafuer zu laden hiesse,
 * fuenf Tabellen samt ihren Einbettungen zu holen, um drei Felder zu zeigen.
 */
final readonly class DocumentHit
{
    public function __construct(
        public string $id,
        public DocumentKind $kind,
        public string $label,
        public int $propertyNumber,
        public int $year,
    ) {
    }
}
