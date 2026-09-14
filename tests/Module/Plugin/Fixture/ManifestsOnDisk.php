<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Fixture;

use App\Module\Plugin\Domain\ManifestSource;

/** Verzeichnisse unter plugins/, ohne dass es sie gibt. */
final class ManifestsOnDisk implements ManifestSource
{
    /** @param array<string, string> $manifests */
    public function __construct(public array $manifests = [])
    {
    }

    public function all(): array
    {
        return $this->manifests;
    }
}
