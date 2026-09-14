<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\DeliveryRepository;
use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginStorage;
use App\Module\Plugin\Domain\TileRepository;
use RuntimeException;

/**
 * Entfernen nimmt die Daten mit.
 *
 * Das Schema bleibt sonst stehen, und niemand raeumt es je wieder auf — nach
 * zwei Jahren stehen dort Tabellen, von denen keiner mehr weiss, wozu sie
 * gehoerten. Die Rueckfrage vor dem Knopf sagt genau das; wer nur abschalten
 * will, setzt aus.
 */
final readonly class RemovePlugin
{
    public function __construct(
        private PluginRepository $plugins,
        private PluginStorage $storage,
        private DeliveryRepository $deliveries,
        private TileRepository $tiles,
    ) {
    }

    public function __invoke(string $name): void
    {
        $plugin = $this->plugins->byName($name)
            ?? throw new RuntimeException(\sprintf('„%s" ist nicht aktiviert.', $name));

        // **Die Zeile geht zuletzt.** Solange sie steht, laesst sich das
        // Entfernen in der Oberflaeche wiederholen. Ginge sie zuerst und
        // scheiterte danach das Abraeumen des Schemas, bliebe ein Speicher
        // zurueck, den die Anwendung nicht mehr kennt und nicht mehr entfernen
        // kann. Jeder Schritt davor verzeiht, zweimal zu laufen.
        $this->storage->drop($name);

        // Was noch auf dem Weg war, geht mit — ebenso die Kacheln. Eine
        // Zustellung an ein Plugin, das es nicht mehr gibt, versuchte es
        // sonst noch viermal, und eine Kachel bliebe bis zum Ablauf ihrer
        // Frist auf der Uebersicht stehen.
        $this->deliveries->forgetPlugin($name);
        $this->tiles->forgetPlugin($name);

        $this->plugins->remove($plugin);
    }
}
