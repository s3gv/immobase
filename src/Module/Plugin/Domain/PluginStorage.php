<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use App\Module\Plugin\Domain\Manifest\TableSpec;

/**
 * Der eigene Speicher eines Plugins.
 *
 * Kein Plugin bringt eine eigene Datenbank mit: nach zwanzig Plugins waeren
 * das zwanzig Datenbanken. Es bekommt ein eigenes Schema in unserer und eine
 * Rolle, die **nur dort** etwas darf.
 *
 * Die Einschraenkung ist der Punkt. Kaeme ein Plugin an die Tabellen des
 * Cores, waere `/api/v1/` sinnlos und unser internes Schema ueber Nacht
 * oeffentliche Zusage — ab dann liesse sich keine Spalte mehr umbenennen,
 * ohne fremde Plugins zu brechen.
 */
interface PluginStorage
{
    /**
     * @param list<TableSpec> $tables
     */
    public function create(string $name, string $password, array $tables): void;

    /**
     * Neue Tabellen und neue Spalten einer neuen Fassung.
     *
     * **Innerhalb von v1 waechst das Schema nur.** Weggefallenes bleibt
     * stehen, bis das Plugin entfernt wird: eine Aktualisierung, die Daten
     * loescht, ist eine Falle.
     *
     * @param list<TableSpec> $tables
     */
    public function grow(string $name, array $tables): void;

    public function drop(string $name): void;

    /** Die Zugangsdaten, die das Plugin sich mit seinem Token abholt. */
    public function dsn(string $name, string $password): string;
}
