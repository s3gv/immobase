<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

interface PluginRepository
{
    public function save(Plugin $plugin): void;

    /**
     * Alles darin gilt gemeinsam — oder gar nicht. Und fuer einen Namen nur einer zugleich.
     *
     * Speicher und Installationszeile liegen auf derselben Verbindung;
     * PostgreSQL nimmt auch CREATE SCHEMA und CREATE ROLE zurueck. Scheitert
     * ein Schritt, steht danach keiner.
     *
     * Wer fuer denselben Namen zugleich kommt, wartet, bis der erste
     * fertig ist — und sieht dann dessen Ergebnis, statt mitten in dessen
     * Anlegen zu scheitern.
     *
     * **Ein Fehler darin laesst die Persistenz geschlossen zurueck.** Was
     * fachlich schiefgehen darf, kommt deshalb als Ergebnis heraus und nicht
     * als Ausnahme.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomicallyFor(string $name, callable $work): mixed;

    public function remove(Plugin $plugin): void;

    public function byName(string $name): ?Plugin;

    /**
     * Die Ports, die schon vergeben sind.
     *
     * @return list<int>
     */
    public function usedPorts(): array;

    /** Gesucht wird ueber den Abdruck: das Token selbst steht nirgends. */
    public function byTokenHash(string $hash): ?Plugin;

    /**
     * Alle Installationen, auch ausgesetzte.
     *
     * @return list<Plugin>
     */
    public function all(): array;

    /**
     * Nur die aktiven — das ist, was Navigation, Rechte und Zustellung sehen.
     *
     * @return list<Plugin>
     */
    public function active(): array;
}
