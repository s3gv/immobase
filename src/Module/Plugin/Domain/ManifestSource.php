<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

/**
 * Woher Manifeste kommen.
 *
 * Heute: das Verzeichnis `plugins/`. Ein Plugin liegt darin, sein Manifest
 * wird gefunden, und damit ist es **gefunden** — mehr nicht. Aktiviert wird
 * es von einem Menschen.
 */
interface ManifestSource
{
    /**
     * Der Inhalt aller gefundenen Manifeste, nach Verzeichnisnamen.
     *
     * @return array<string, string>
     */
    public function all(): array;
}
