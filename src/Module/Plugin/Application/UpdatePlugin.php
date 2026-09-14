<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginStorage;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Eine neue Fassung desselben Plugins, erneut bestaetigt.
 *
 * Das Token bleibt: es gehoert der Installation und nicht der Fassung. Was
 * sich aendert, ist die Momentaufnahme — und damit die Menuepunkte, die
 * Rechte und die Tabellen.
 */
final readonly class UpdatePlugin
{
    public function __construct(
        private ReadsManifests $manifests,
        private PluginRepository $plugins,
        private PluginStorage $storage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $name, string $actor): void
    {
        $plugin = $this->plugins->byName($name)
            ?? throw new RuntimeException(\sprintf('„%s" ist nicht aktiviert.', $name));

        $manifest = $this->manifests->of($name);

        $this->storage->grow($name, $manifest->tables);
        $plugin->updateTo($manifest->version, $this->manifests->jsonOf($name), $this->clock->now(), $actor);
        $this->plugins->save($plugin);
    }
}
