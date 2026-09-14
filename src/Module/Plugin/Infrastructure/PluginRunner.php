<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use RuntimeException;

/**
 * Unter welchem Benutzer ein Plugin-Prozess laeuft.
 *
 * Im Image startet er ueber `run-as-plugin` (docker/plugin-runner/): ein
 * kleiner Helfer, der auf die Kennung des Plugins wechselt und auf keine
 * andere. Unter dem Benutzer des Cores koennte ein Plugin dessen Umgebung aus
 * `/proc` lesen — Datenbankzugang und APP_SECRET — und seine Prozesse
 * beenden. Unter einer gemeinsamen Kennung aller Plugins laese eines das Token
 * des anderen. Unter einer eigenen kann es nichts davon.
 *
 * **Die Kennung folgt aus dem Port**: 100000 plus Port. Den vergibt die
 * Aktivierung ohnehin eindeutig, und so braucht es keine zweite Zuteilung,
 * die mit der ersten auseinanderlaufen koennte.
 *
 * Ohne Helfer, auf dem Entwicklungsrechner und in den Tests, laeuft alles
 * unter dem eigenen Benutzer wie zuvor.
 */
final readonly class PluginRunner
{
    private const int FIRST_UID = 100000;

    /** SIGTERM, als Zahl — die Konstante gehoert zu pcntl, und das fehlt im Image. */
    private const int TERMINATE = 15;

    public function __construct(private ?string $helper)
    {
    }

    public static function uidFor(int $port): int
    {
        return self::FIRST_UID + $port;
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    public function wrap(array $command, int $port): array
    {
        return null === $this->helper ? $command : [$this->helper, 'exec', (string) self::uidFor($port), ...$command];
    }

    /**
     * Wo Caddy im Plugin-Prozess seinen Zustand ablegt.
     *
     * Unter der Kennung des Plugins nicht in var/ des Cores: dort darf es
     * nicht schreiben, und es soll es auch nicht. Der Helfer legt das
     * Verzeichnis selbst an und prueft, dass es wirklich dem Plugin gehoert.
     */
    public function homeFor(string $stateDirectory, string $name, int $port): string
    {
        return null === $this->helper
            ? $stateDirectory.'/'.$name.'.caddy'
            : '/tmp/immobase-plugin-'.self::uidFor($port);
    }

    public function terminate(int $pid, int $port): void
    {
        if (null === $this->helper) {
            posix_kill($pid, self::TERMINATE);

            return;
        }

        $handle = proc_open([$this->helper, 'kill', (string) self::uidFor($port), (string) $pid], [], $pipes);

        if (!\is_resource($handle)) {
            throw new RuntimeException(\sprintf('Der Plugin-Prozess %d ließ sich nicht beenden.', $pid));
        }

        proc_close($handle);
    }
}
