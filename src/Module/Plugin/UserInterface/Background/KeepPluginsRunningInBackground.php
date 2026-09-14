<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\UserInterface\Background;

use App\Module\Plugin\Application\KeepPluginsRunning;
use App\Shared\Background\RunsInBackground;

/**
 * Die Plugin-Prozesse abgleichen.
 *
 * Alle paar Sekunden: wer ein Plugin aktiviert, soll nicht eine Minute auf
 * seinen Menuepunkt warten.
 */
final readonly class KeepPluginsRunningInBackground implements RunsInBackground
{
    public function __construct(private KeepPluginsRunning $keep)
    {
    }

    public function name(): string
    {
        return 'plugins';
    }

    public function everySeconds(): int
    {
        return 5;
    }

    public function run(): void
    {
        ($this->keep)();
    }
}
