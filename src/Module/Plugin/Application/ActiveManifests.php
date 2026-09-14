<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Manifest\Manifest;
use App\Module\Plugin\Domain\Manifest\ManifestFault;
use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginRepository;
use App\Shared\Write\ObservesWrites;

/**
 * Die bestaetigten Manifeste der aktiven Plugins.
 *
 * Aus der Datenbank und nicht von der Platte: Navigation und Rechte werden
 * bei jedem Seitenaufbau gebraucht, und eine Anwendung, die dafuer
 * Verzeichnisse abtastet, wird mit jedem Plugin langsamer. Innerhalb eines
 * Requests wird einmal gelesen.
 *
 * **Der Zwischenspeicher haelt genau so lange, wie er stimmt.** Wird eine
 * Installation angelegt, ausgesetzt oder entfernt, wird er verworfen — sonst
 * liefe ein Request mit einem Stand weiter, den es nicht mehr gibt, und die
 * gerade bestaetigten Rechte fehlten bis zum naechsten Aufruf.
 *
 * **Ein unlesbares Manifest faellt hier stillschweigend heraus.** Es kommt
 * vor: der Core wird aktualisiert und nimmt eine Fassung nicht mehr an. Die
 * Alternative waere, dass jede Seite der Anwendung mit einer Ausnahme
 * antwortet, weil ein Plugin nicht mehr passt — das waere genau die
 * Abhaengigkeit, die es nicht geben soll. Auf der Einstellungsseite steht der
 * Fehler dafuer im Klartext.
 */
final class ActiveManifests implements ObservesWrites
{
    /** @var list<array{string, Manifest}>|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly PluginRepository $plugins,
        private readonly ReadsManifests $manifests,
    ) {
    }

    /**
     * @return list<array{string, Manifest}>
     */
    public function all(): array
    {
        if (null !== $this->loaded) {
            return $this->loaded;
        }

        $found = [];

        foreach ($this->plugins->active() as $plugin) {
            try {
                $found[] = [$plugin->name(), $this->manifests->stored($plugin->name(), $plugin->manifest())];
            } catch (ManifestFault) {
                continue;
            }
        }

        return $this->loaded = $found;
    }

    public function saw(array $writes): void
    {
        foreach ($writes as $write) {
            if ($write->entity instanceof Plugin) {
                $this->loaded = null;

                return;
            }
        }
    }

    public function of(string $name): ?Manifest
    {
        foreach ($this->all() as [$found, $manifest]) {
            if ($found === $name) {
                return $manifest;
            }
        }

        return null;
    }
}
