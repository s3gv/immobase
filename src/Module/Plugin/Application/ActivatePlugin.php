<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginAlreadyActive;
use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginStorage;
use App\Module\Plugin\Domain\PluginToken;
use Symfony\Component\Clock\ClockInterface;

/**
 * Aktivieren heisst vertrauen.
 *
 * Erst hier bekommt ein Plugin Rechte, Menuepunkte, Tabellen und ein Token.
 * Davor liegt es nur da. Der Mensch, der zustimmt, steht mit Namen in der
 * Zeile — nicht „System", nicht „Installer".
 *
 * **In einer einzigen Transaktion**: die Pruefung, ob es die Installation
 * schon gibt, der Speicher und die Zeile. Scheitert irgendetwas davon, steht
 * danach nichts — kein halbes Schema, keine Rolle, keine Zeile.
 *
 * **Und ohne Aufraeumen danach.** Ein Aufraeumen im Fehlerfall waere bei zwei
 * gleichzeitigen Aktivierungen gefaehrlich: die zweite scheitert, weil die
 * erste das Schema schon angelegt hat — und raeumte dann den Speicher der
 * ersten weg. So wartet die zweite, bis die erste fertig ist, und findet
 * dann deren Installation vor.
 *
 * **„Schon aktiviert" ist ein Ergebnis, keine Ausnahme in der Transaktion.**
 * Eine Ausnahme darin schloesse die Persistenz fuer den Rest des Requests;
 * geworfen wird deshalb erst, wenn die Transaktion sauber verlassen ist.
 *
 * @throws PluginAlreadyActive
 */
final readonly class ActivatePlugin
{
    /** Ab hier werden Ports vergeben — unterhalb liegt, was der Container sonst benutzt. */
    private const int FIRST_PORT = 9100;

    public function __construct(
        private ReadsManifests $manifests,
        private PluginRepository $plugins,
        private PluginStorage $storage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $name, string $actor): void
    {
        // Ein Manifest, das nicht passt, scheitert hier — vor jeder Transaktion.
        $this->manifests->of($name);

        $activated = $this->plugins->atomicallyFor(
            $name,
            fn (): bool => null === $this->plugins->byName($name) && $this->install($name, $actor),
        );

        if (!$activated) {
            throw new PluginAlreadyActive($name);
        }
    }

    /** Speicher und Installationszeile — innerhalb der Transaktion. */
    private function install(string $name, string $actor): bool
    {
        $manifest = $this->manifests->of($name);
        $password = bin2hex(random_bytes(24));
        $this->storage->create($name, $password, $manifest->tables);

        $this->plugins->save(new Plugin(
            $name,
            $manifest->version,
            $this->manifests->jsonOf($name),
            // Ein Abdruck, zu dem es kein Token gibt: gueltig wird eines
            // erst, wenn der Prozess startet und sein eigenes bekommt.
            PluginToken::seal(PluginToken::fresh()),
            $password,
            $this->clock->now(),
            $actor,
            $this->freePort(),
        ));

        return true;
    }

    private function freePort(): int
    {
        $used = $this->plugins->usedPorts();
        $port = self::FIRST_PORT;

        while (\in_array($port, $used, true)) {
            ++$port;
        }

        return $port;
    }
}
