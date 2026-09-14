<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

/**
 * Ist dieser Prozess noch der, den wir gestartet haben?
 *
 * **Eine Kennung allein beweist das nicht.** Das Betriebssystem vergibt
 * Kennungen neu; eine PID-Datei von gestern kann heute auf den Webserver
 * oder den Hintergrundlauf zeigen. Wer dann „Plugin aussetzen" drueckt,
 * beendete den falschen Prozess — und ein Plugin, dessen Kennung zufaellig
 * belegt ist, wuerde nie wieder gestartet.
 *
 * Verglichen wird deshalb die vollstaendige Befehlszeile: der Prozess muss
 * genau mit dem Kommando laufen, mit dem er gestartet wurde.
 *
 * Gelesen wird aus `/proc` — die Anwendung laeuft in einem Linux-Container.
 * Wo es `/proc` nicht gibt, ist kein Prozess als unserer erkennbar; dann
 * wird lieber einmal zu oft gestartet als einmal der falsche beendet.
 */
final readonly class ProcessIdentity
{
    public function __construct(private string $procRoot = '/proc')
    {
    }

    /**
     * @param list<string> $command
     */
    public function isOurs(int $pid, array $command): bool
    {
        $file = $this->procRoot.'/'.$pid.'/cmdline';
        $raw = is_readable($file) ? file_get_contents($file) : false;

        return \is_string($raw) && '' !== $raw && rtrim($raw, "\0") === implode("\0", $command);
    }
}
