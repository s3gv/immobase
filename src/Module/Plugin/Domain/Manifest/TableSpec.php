<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

/** Eine angemeldete Tabelle. */
final readonly class TableSpec
{
    /**
     * @param non-empty-list<ColumnSpec> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
    ) {
    }
}
