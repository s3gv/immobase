<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginState;
use RuntimeException;

/** Aussetzen und wieder aufnehmen — ohne Daten zu verlieren. */
final readonly class SetPluginState
{
    public function __construct(private PluginRepository $plugins)
    {
    }

    public function __invoke(string $name, PluginState $state): void
    {
        $plugin = $this->plugins->byName($name)
            ?? throw new RuntimeException(\sprintf('„%s" ist nicht aktiviert.', $name));

        PluginState::Active === $state ? $plugin->resume() : $plugin->suspend();

        $this->plugins->save($plugin);
    }
}
