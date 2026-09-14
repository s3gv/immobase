<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

/**
 * Die laufenden Plugin-Prozesse.
 *
 * **Im Anwendungscontainer, aber nicht in der Anwendung.** Jedes Plugin ist
 * ein eigener PHP-Prozess mit eigenem Autoloader auf eigenem Port. Das ist
 * die Lizenzgrenze: was im selben Prozess Klassen des Cores laedt, waere mit
 * ihm ein gemeinsames Werk. Einen eigenen Container braucht es dafuer nicht.
 */
interface PluginProcesses
{
    /**
     * Die Namen der Plugins, deren Prozess gerade laeuft.
     *
     * @return list<string>
     */
    public function running(): array;

    /**
     * @param array<string, string> $environment genau das, was der Prozess sehen soll
     */
    public function start(string $name, int $port, array $environment): void;

    public function stop(string $name): void;
}
