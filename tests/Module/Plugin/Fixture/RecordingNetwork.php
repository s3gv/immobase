<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Fixture;

use App\Module\Plugin\Domain\PluginNetwork;
use RuntimeException;

/** Eine Netzsperre, die nur aufschreibt, was sie setzen soll. */
final class RecordingNetwork implements PluginNetwork
{
    /** @var list<list<int>> */
    public array $restricted = [];

    public bool $failing = false;

    public function restrict(array $withInternet): void
    {
        if ($this->failing) {
            throw new RuntimeException('Die Netzsperre für Plugins ließ sich nicht setzen.');
        }

        $this->restricted[] = $withInternet;
    }
}
