<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Manifest\Manifest;
use App\Module\Plugin\Domain\Manifest\ManifestFault;
use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginRepository;

/**
 * Was da ist und was davon laeuft.
 *
 * Zwei Quellen kommen zusammen: die Verzeichnisse unter `plugins/` und die
 * Installationen in der Datenbank. Aus beiden Richtungen kann etwas fehlen —
 * ein neues Plugin liegt da und ist nicht aktiviert; ein aktiviertes ist von
 * der Platte verschwunden. Beides gehoert sichtbar auf die Seite, das zweite
 * besonders: es ist der Fall, in dem Menuepunkte auf etwas zeigen, das es
 * nicht mehr gibt.
 *
 * **Nur fuer die Einstellungsseite.** Navigation, Rechte und Zustellung lesen
 * die Momentaufnahme aus der Datenbank; sie tasten kein Verzeichnis ab.
 */
final readonly class DiscoverPlugins
{
    public function __construct(
        private ReadsManifests $manifests,
        private PluginRepository $plugins,
    ) {
    }

    /**
     * @return list<FoundPlugin>
     */
    public function __invoke(): array
    {
        $onDisk = $this->manifests->json();
        $installed = [];

        foreach ($this->plugins->all() as $plugin) {
            $installed[$plugin->name()] = $plugin;
        }

        $names = array_unique([...array_keys($onDisk), ...array_keys($installed)]);
        sort($names);

        return array_values(array_map(
            fn (string $name): FoundPlugin => $this->describe($name, $onDisk[$name] ?? null, $installed[$name] ?? null),
            $names,
        ));
    }

    private function describe(string $name, ?string $json, ?Plugin $installed): FoundPlugin
    {
        [$manifest, $problem] = $this->parse($name, $json ?? $installed?->manifest());

        if (null !== $installed && null === $json) {
            $problem = 'plugin.problem.missing';
        }

        return new FoundPlugin(
            $name,
            $manifest,
            $problem,
            $installed?->state(),
            $installed?->version() ?? '',
            $this->wantsMore($manifest, $installed),
            null !== $json,
        );
    }

    /**
     * @return array{?Manifest, string}
     */
    private function parse(string $name, ?string $json): array
    {
        if (null === $json) {
            return [null, 'plugin.problem.missing'];
        }

        try {
            return [$this->manifests->stored($name, $json), ''];
        } catch (ManifestFault $fault) {
            return [null, $fault->getMessage()];
        }
    }

    /**
     * Verlangt die Fassung auf der Platte mehr als die bestaetigte?
     *
     * Dann ist sie nicht einfach neuer, sondern braucht eine neue
     * Zustimmung — sonst waechst die alte stillschweigend mit.
     */
    private function wantsMore(?Manifest $manifest, ?Plugin $installed): bool
    {
        if (null === $manifest || null === $installed) {
            return false;
        }

        try {
            return $manifest->wantsMoreThan($this->manifests->stored($installed->name(), $installed->manifest()));
        } catch (ManifestFault) {
            // Die bestaetigte Fassung laesst sich nicht mehr lesen. Dann ist
            // jede neue Frage die sicherere Antwort.
            return true;
        }
    }
}
