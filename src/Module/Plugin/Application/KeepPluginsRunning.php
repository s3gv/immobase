<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Manifest\ManifestFault;
use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginNetwork;
use App\Module\Plugin\Domain\PluginProcesses;
use App\Module\Plugin\Domain\PluginRepository;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

/**
 * Sorgt dafuer, dass genau die aktiven Plugins laufen.
 *
 * Ein Durchgang vergleicht, was laufen soll, mit dem, was laeuft: ein
 * aktiviertes Plugin ohne Prozess wird gestartet, ein Prozess ohne aktives
 * Plugin — ausgesetzt, entfernt, von der Platte verschwunden — wird beendet.
 * Wer ein Plugin in den Einstellungen aktiviert, muss sonst nichts tun.
 *
 * **Jeder Start bekommt ein frisches Token.** Es geht dem Prozess ueber
 * seine Umgebung zu; niemand schreibt es ab, und das vorige gilt ab diesem
 * Moment nicht mehr.
 *
 * **Ein abstuerzendes Plugin wird nicht im Sekundentakt neu gestartet.**
 * Zwischen zwei Starts desselben Plugins liegt eine Pause — ein Plugin, das
 * beim Start zusammenbricht, soll die Maschine nicht beschaeftigen und das
 * Aenderungsprotokoll nicht mit Tokenwechseln fuellen.
 *
 * **Ein Plugin, das nicht startet, haelt die anderen nicht auf.** Sein Fehler
 * wird gesammelt und am Ende des Durchgangs gemeldet — nachdem alle anderen
 * ihre Chance hatten.
 */
final class KeepPluginsRunning
{
    private const int PAUSE_SECONDS = 30;

    /** @var array<string, int> Zeitpunkt des letzten Starts je Plugin */
    private array $startedAt = [];

    public function __construct(
        private readonly PluginRepository $plugins,
        private readonly ReadsManifests $manifests,
        private readonly PluginProcesses $processes,
        private readonly IssueToken $tokens,
        private readonly ClockInterface $clock,
        private readonly string $coreUrl,
        private readonly PluginNetwork $network,
    ) {
    }

    public function __invoke(): void
    {
        $wanted = $this->wanted();
        $running = $this->processes->running();

        foreach ($running as $name) {
            if (!isset($wanted[$name])) {
                $this->processes->stop($name);
            }
        }

        // Die Sperre vor jedem Start. Scheitert sie, startet in diesem
        // Durchgang nichts — ein Plugin ohne Sperre erreichte alles, was der
        // Core erreicht. Beendet ist dann trotzdem schon, was nicht mehr
        // laufen soll.
        $this->network->restrict($this->withInternet($wanted));

        $failures = $this->startMissing($wanted, $running);

        if ([] !== $failures) {
            throw new RuntimeException(implode("\n", $failures));
        }
    }

    /**
     * @param array<string, Plugin> $wanted
     * @param list<string>          $running
     *
     * @return list<string> die Fehler der Starts, die nicht gelangen
     */
    private function startMissing(array $wanted, array $running): array
    {
        $failures = [];

        foreach ($wanted as $name => $plugin) {
            if (\in_array($name, $running, true) || $this->isPausing($name)) {
                continue;
            }

            try {
                $this->start($plugin);
            } catch (Throwable $failed) {
                $failures[] = $failed->getMessage();
            }
        }

        return $failures;
    }

    /**
     * Aktiv und auf der Platte — beides.
     *
     * @return array<string, Plugin>
     */
    private function wanted(): array
    {
        $onDisk = $this->manifests->json();
        $wanted = [];

        foreach ($this->plugins->active() as $plugin) {
            if (isset($onDisk[$plugin->name()])) {
                $wanted[$plugin->name()] = $plugin;
            }
        }

        return $wanted;
    }

    /**
     * Die Ports der Plugins, denen das Internet zugestanden ist.
     *
     * Aus dem Manifest, dem zugestimmt wurde, nicht aus dem auf der Platte:
     * eine neue Fassung, die das Internet verlangt, bekommt es erst mit der
     * neuen Zustimmung.
     *
     * @param array<string, Plugin> $wanted
     *
     * @return list<int>
     */
    private function withInternet(array $wanted): array
    {
        $ports = [];

        foreach ($wanted as $name => $plugin) {
            try {
                $internet = $this->manifests->stored($name, $plugin->manifest())->internet;
            } catch (ManifestFault) {
                // Ein Manifest, das sich nicht mehr lesen laesst, bekommt
                // nichts dazu — und haelt die anderen nicht auf.
                $internet = false;
            }

            if ($internet) {
                $ports[] = $plugin->port();
            }
        }

        return $ports;
    }

    private function start(Plugin $plugin): void
    {
        $this->startedAt[$plugin->name()] = $this->clock->now()->getTimestamp();

        $this->processes->start($plugin->name(), $plugin->port(), [
            'IMMOBASE_URL' => $this->coreUrl,
            'IMMOBASE_TOKEN' => ($this->tokens)($plugin->name()),
        ]);
    }

    private function isPausing(string $name): bool
    {
        $last = $this->startedAt[$name] ?? null;

        return null !== $last && $this->clock->now()->getTimestamp() - $last < self::PAUSE_SECONDS;
    }
}
