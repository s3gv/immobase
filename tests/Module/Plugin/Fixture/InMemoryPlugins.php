<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Fixture;

use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginState;

/** Installationen ohne Datenbank — fuer die Entscheidungen des Aufsehers. */
final class InMemoryPlugins implements PluginRepository
{
    /** @var array<string, Plugin> */
    public array $plugins = [];

    public function save(Plugin $plugin): void
    {
        $this->plugins[$plugin->name()] = $plugin;
    }

    public function atomicallyFor(string $name, callable $work): mixed
    {
        return $work();
    }

    public function remove(Plugin $plugin): void
    {
        unset($this->plugins[$plugin->name()]);
    }

    public function byName(string $name): ?Plugin
    {
        return $this->plugins[$name] ?? null;
    }

    public function usedPorts(): array
    {
        return array_values(array_map(static fn (Plugin $plugin): int => $plugin->port(), $this->plugins));
    }

    public function byTokenHash(string $hash): ?Plugin
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->tokenHash() === $hash) {
                return $plugin;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->plugins);
    }

    public function active(): array
    {
        return array_values(array_filter($this->plugins, static fn (Plugin $plugin): bool => PluginState::Active === $plugin->state()));
    }
}
