<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Fixture;

use App\Module\Plugin\Domain\PluginProcesses;
use RuntimeException;

/** Prozesse, die nur aufschreiben, was mit ihnen geschehen soll. */
final class RecordingProcesses implements PluginProcesses
{
    /** @var array<string, array{port: int, environment: array<string, string>}> */
    public array $alive = [];

    /** @var list<string> */
    public array $started = [];

    /** @var list<string> */
    public array $stopped = [];

    /** @var array<string, string> Plugins, deren Start scheitert — mit der Meldung */
    public array $failing = [];

    public function running(): array
    {
        return array_keys($this->alive);
    }

    public function start(string $name, int $port, array $environment): void
    {
        if (isset($this->failing[$name])) {
            throw new RuntimeException($this->failing[$name]);
        }

        $this->alive[$name] = ['port' => $port, 'environment' => $environment];
        $this->started[] = $name;
    }

    public function stop(string $name): void
    {
        unset($this->alive[$name]);
        $this->stopped[] = $name;
    }

    /** Ein Absturz: der Prozess ist weg, ohne dass jemand ihn beendet hat. */
    public function crash(string $name): void
    {
        unset($this->alive[$name]);
    }
}
