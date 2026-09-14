<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginStorage;

/**
 * Die Zugangsdaten, die ein Plugin sich selbst abholt.
 *
 * **Ein Geheimnis reicht.** Der Betreiber traegt das Token beim Plugin ein,
 * alles Weitere verhandeln die beiden. Stuende die Datenbankverbindung in
 * einer zweiten Datei, waere sie die zweite Stelle, an der jemand sie
 * vergisst, verwechselt oder versehentlich einckeckt.
 *
 * Nur fuer sich selbst: ein Plugin erfaehrt hier nichts ueber ein anderes.
 */
final readonly class StorageFor
{
    public function __construct(
        private PluginRepository $plugins,
        private PluginStorage $storage,
    ) {
    }

    public function __invoke(string $name): ?string
    {
        $plugin = $this->plugins->byName($name);

        return null === $plugin ? null : $this->storage->dsn($name, $plugin->storagePassword());
    }
}
