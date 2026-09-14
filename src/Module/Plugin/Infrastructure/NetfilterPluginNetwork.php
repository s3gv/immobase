<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\PluginNetwork;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Die Netzsperre ueber `plugin-firewall` (docker/plugin-runner/).
 *
 * Der Helfer schreibt die Regeln fuer den ganzen Kennungsbereich der Plugins
 * jedes Mal vollstaendig neu. Aufgerufen wird er nur, wenn sich etwas
 * geaendert hat: die Plugins mit Internet, oder die Adresse der Datenbank —
 * die bekommt ein Datenbankcontainer beim Neuerstellen gern neu.
 *
 * Die Datenbank steht als IP-Adresse in der Regel und im Zugang, den das
 * Plugin bekommt: ein Plugin ohne Internet hat keinen Namensdienst.
 *
 * Ohne Helfer, auf dem Entwicklungsrechner und in den Tests, gibt es keine
 * Sperre — dort laufen Plugins ohnehin unter dem eigenen Benutzer.
 */
final class NetfilterPluginNetwork implements PluginNetwork
{
    private ?string $applied = null;

    public function __construct(
        private readonly ?string $helper,
        private readonly string $coreUrl,
        private readonly Connection $connection,
    ) {
    }

    public function restrict(array $withInternet): void
    {
        if (null === $this->helper || '' === $this->helper) {
            return;
        }

        $arguments = [
            (string) (parse_url($this->coreUrl, \PHP_URL_PORT) ?? 80),
            (string) DatabaseAddress::of($this->connection)->port,
            implode(',', DatabaseAddress::of($this->connection)->ipv4()),
            ...array_map(static fn (int $port): string => (string) PluginRunner::uidFor($port), $withInternet),
        ];

        $signature = implode(' ', $arguments);

        if ($signature === $this->applied) {
            return;
        }

        $this->run($arguments);
        $this->applied = $signature;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): void
    {
        $handle = proc_open([(string) $this->helper, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = $pipes[1] ?? null;
        $errors = $pipes[2] ?? null;

        // Ohne CAP_NET_ADMIN im Container verweigert schon der Start des
        // Helfers, und er kann nichts mehr dazu sagen.
        if (!\is_resource($handle) || !\is_resource($output) || !\is_resource($errors)) {
            throw new RuntimeException('Die Netzsperre für Plugins ließ sich nicht setzen. Startet der Container mit cap_add: NET_ADMIN?');
        }

        $said = trim(stream_get_contents($errors).' '.stream_get_contents($output));
        fclose($output);
        fclose($errors);

        if (0 !== proc_close($handle)) {
            throw new RuntimeException('Die Netzsperre für Plugins ließ sich nicht setzen: '.('' === $said ? 'Startet der Container mit cap_add: NET_ADMIN?' : $said));
        }
    }
}
