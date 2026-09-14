<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Api;

/**
 * Eine Seite Daten, wie sie hinausgeht.
 *
 * **Flach und ohne Entitaeten.** Was hier steht, ist eine oeffentliche
 * Zusage: Dritte binden sich an diese Felder. Eine Entity waere dagegen ein
 * Versprechen ueber unsere inneren Verhaeltnisse, das wir nicht halten
 * koennen.
 */
final readonly class ApiPage
{
    /**
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        public array $data,
        public int $page,
        public int $pages,
        public int $total,
    ) {
    }
}
