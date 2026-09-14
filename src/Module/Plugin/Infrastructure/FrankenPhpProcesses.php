<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\PluginProcesses;
use RuntimeException;

/**
 * Plugin-Prozesse als eigene FrankenPHP-Server.
 *
 * Dieselbe Laufzeit wie der Core, aber ein eigener Prozess: `frankenphp run`
 * mit einer eigenen Caddyfile, das `public/`-Verzeichnis des Plugins als
 * Wurzel, an 127.0.0.1 gebunden. Nach aussen ist er nicht erreichbar; wer eine
 * Seite will, geht ueber den Core und dessen Rechtepruefung.
 *
 * Die Umgebung erbt er nicht — siehe {@see ProcessEnvironment}.
 *
 * **Die Kennungen stehen in Dateien**, nicht nur im Arbeitsspeicher. Stirbt
 * der Aufseher und startet neu, findet er seine Prozesse wieder, statt einen
 * zweiten auf denselben Port zu setzen. Neben der Kennung steht die
 * Befehlszeile: eine Kennung allein beweist nicht, dass der Prozess noch
 * unserer ist — siehe {@see ProcessIdentity}.
 */
final class FrankenPhpProcesses implements PluginProcesses
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(
        private readonly string $pluginsDirectory,
        private readonly string $stateDirectory,
        private readonly ProcessIdentity $identity,
        private readonly PluginDependencies $dependencies,
        private readonly string $caddyfile,
        private readonly PluginRunner $runner = new PluginRunner(null),
        private readonly string $binary = 'frankenphp',
    ) {
    }

    public function running(): array
    {
        $found = [];
        $files = glob($this->stateDirectory.'/*.pid');

        foreach (false === $files ? [] : $files as $file) {
            $name = basename($file, '.pid');

            if ($this->isAlive($name)) {
                $found[] = $name;
            } else {
                unlink($file);
            }
        }

        sort($found);

        return $found;
    }

    public function start(string $name, int $port, array $environment): void
    {
        $directory = $this->pluginsDirectory.'/'.$name;
        $this->dependencies->installIn($directory);
        $this->ensureStateDirectory();

        $log = $this->logFor($name);
        $command = [$this->binary, 'run', '--adapter', 'caddyfile', '--config', $this->caddyfile];
        $caddy = ProcessEnvironment::forCaddy($this->runner->homeFor($this->stateDirectory, $name, $port), $port, $directory.'/public');
        // Festgehalten wird der Befehl ohne Helfer: der ersetzt sich beim
        // Start durch ihn, und so steht er dann in /proc.
        $handle = proc_open(
            $this->runner->wrap($command, $port),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $directory,
            ProcessEnvironment::only([...$environment, ...$caddy]),
        );

        if (!\is_resource($handle)) {
            throw new RuntimeException(\sprintf('Der Prozess für „%s" ließ sich nicht starten.', $name));
        }

        $this->handles[$name] = $handle;
        file_put_contents($this->pidFile($name), json_encode([
            'pid' => proc_get_status($handle)['pid'],
            'port' => $port,
            'command' => $command,
        ], \JSON_THROW_ON_ERROR));
    }

    public function stop(string $name): void
    {
        $recorded = $this->recorded($name);

        // Nur beenden, was nachweislich unserer ist. Eine wiederverwendete
        // Kennung kann auf den Webserver zeigen.
        if (null !== $recorded && $this->identity->isOurs($recorded['pid'], $recorded['command'])) {
            $this->runner->terminate($recorded['pid'], $recorded['port']);
        }

        if (isset($this->handles[$name])) {
            proc_close($this->handles[$name]);
            unset($this->handles[$name]);
        }

        if (is_file($this->pidFile($name))) {
            unlink($this->pidFile($name));
        }
    }

    /**
     * Kennungen und Protokolle gehoeren dem Core. Ein Plugin laeuft unter einer
     * anderen Kennung und liest hier nichts — auch nicht das Protokoll eines
     * anderen Plugins, in dem stehen kann, was dessen Seiten ausgegeben haben.
     */
    private function ensureStateDirectory(): void
    {
        if (!is_dir($this->stateDirectory) && !mkdir($this->stateDirectory, 0o700, true) && !is_dir($this->stateDirectory)) {
            throw new RuntimeException('Das Verzeichnis für Plugin-Prozesse ließ sich nicht anlegen.');
        }

        chmod($this->stateDirectory, 0o700);
    }

    private function logFor(string $name): string
    {
        $log = $this->stateDirectory.'/'.$name.'.log';

        if (!is_file($log)) {
            touch($log);
        }

        chmod($log, 0o640);

        return $log;
    }

    /**
     * Laeuft der Prozess noch?
     *
     * Ueber das eigene Handle, wenn es eines gibt — das raeumt einen beendeten
     * Prozess dabei ab, statt ihn als Zombie weiterzaehlen zu lassen. Sonst
     * ueber die Kennung aus der Datei: ein Prozess, den ein frueherer
     * Aufseher gestartet hat.
     */
    private function isAlive(string $name): bool
    {
        if (isset($this->handles[$name])) {
            if (proc_get_status($this->handles[$name])['running']) {
                return true;
            }

            proc_close($this->handles[$name]);
            unset($this->handles[$name]);

            return false;
        }

        $recorded = $this->recorded($name);

        return null !== $recorded && $this->identity->isOurs($recorded['pid'], $recorded['command']);
    }

    /**
     * Kennung und Befehlszeile, wie sie beim Start festgehalten wurden.
     *
     * @return array{pid: int, port: int, command: list<string>}|null
     */
    private function recorded(string $name): ?array
    {
        $file = $this->pidFile($name);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!\is_array($data) || !\is_int($data['pid'] ?? null) || $data['pid'] <= 0 || !\is_int($data['port'] ?? null)) {
            return null;
        }

        $command = self::stringsOrNull($data['command'] ?? null);

        return null === $command ? null : ['pid' => $data['pid'], 'port' => $data['port'], 'command' => $command];
    }

    /**
     * @return list<string>|null
     */
    private static function stringsOrNull(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $parts = [];

        foreach ($value as $part) {
            if (!\is_string($part)) {
                return null;
            }

            $parts[] = $part;
        }

        return $parts;
    }

    private function pidFile(string $name): string
    {
        return $this->stateDirectory.'/'.$name.'.pid';
    }
}
