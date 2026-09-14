<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

/** Eine angemeldete Spalte. */
final readonly class ColumnSpec
{
    public function __construct(
        public string $name,
        public ColumnType $type,
        public bool $primary = false,
        public bool $nullable = false,
        public bool $index = false,
    ) {
    }
}
