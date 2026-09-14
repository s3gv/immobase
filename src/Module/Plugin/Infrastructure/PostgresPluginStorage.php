<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\Manifest\TableSpec;
use App\Module\Plugin\Domain\PluginStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use RuntimeException;

/**
 * Ein Schema und eine Rolle, die nur darin etwas darf.
 *
 * Die Rolle bekommt `USAGE` auf ihr Schema und Lesen und Schreiben auf dessen
 * Tabellen — sonst nichts. Sie darf dort **nicht selbst anlegen**: was es an
 * Tabellen gibt, steht im Manifest, und was im Manifest steht, hat jemand
 * gelesen, bevor er zugestimmt hat. Ein Plugin, das sich zur Laufzeit
 * Tabellen baut, umginge genau diese Zustimmung.
 *
 * Auf `public` wird ihr ausdruecklich alles entzogen. Ohne Zuteilung kaeme
 * sie ohnehin an keine Tabelle des Cores — aber „ohnehin" ist keine Zusage,
 * und diese Zeile macht sie zu einer.
 */
final readonly class PostgresPluginStorage implements PluginStorage
{
    public function __construct(
        private Connection $connection,
        private TableStatements $statements,
    ) {
    }

    public function create(string $name, string $password, array $tables): void
    {
        $schema = self::schemaOf($name);
        $role = $this->quote($schema);

        // In einer Transaktion: PostgreSQL nimmt auch CREATE ROLE und CREATE
        // SCHEMA zurueck. Scheitert eine Tabelle, stehen danach weder Schema
        // noch Rolle da.
        $this->connection->transactional(function () use ($role, $password, $name, $tables): void {
            $this->run([
                \sprintf('CREATE SCHEMA %s', $role),
                \sprintf('CREATE ROLE %s LOGIN PASSWORD %s', $role, $this->connection->quote($password)),
                \sprintf('REVOKE ALL ON SCHEMA public FROM %s', $role),
                \sprintf('GRANT USAGE ON SCHEMA %s TO %s', $role, $role),
            ]);

            $this->grow($name, $tables);
        });
    }

    public function grow(string $name, array $tables): void
    {
        $schema = self::schemaOf($name);
        $existing = $this->existingColumns($schema);
        $statements = [];

        foreach ($tables as $table) {
            $statements = [...$statements, ...$this->statementsFor($schema, $table, $existing)];
        }

        // Nach jedem Wachsen neu zuteilen: eine Tabelle, die es beim
        // Aktivieren noch nicht gab, waere sonst fuer das Plugin unsichtbar.
        $statements[] = \sprintf(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %s TO %s',
            $this->quote($schema),
            $this->quote($schema),
        );

        $this->run($statements);
    }

    /**
     * Schema und Rolle raeumen — auch, wenn nur die Haelfte dasteht.
     *
     * `REASSIGN OWNED BY` kennt kein `IF EXISTS`. Ohne die Abfrage vorher
     * scheiterte genau der Weg, fuer den es dieses Aufraeumen gibt: eine
     * Aktivierung, die mittendrin abgebrochen ist — und der eigentliche
     * Fehler waere von diesem verdeckt.
     */
    public function drop(string $name): void
    {
        $schema = self::schemaOf($name);
        $role = $this->quote($schema);
        $statements = [\sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $role)];

        if ($this->roleExists($schema)) {
            // Ohne diese Zeilen bliebe die Rolle als Rechtezuweisung an
            // geloeschten Objekten haengen und DROP ROLE schluege fehl.
            $statements[] = \sprintf('REASSIGN OWNED BY %s TO CURRENT_USER', $role);
            $statements[] = \sprintf('DROP OWNED BY %s', $role);
            $statements[] = \sprintf('DROP ROLE %s', $role);
        }

        $this->run($statements);
    }

    public function dsn(string $name, string $password): string
    {
        $parts = $this->connection->getParams();
        $address = DatabaseAddress::of($this->connection);
        $database = \is_string($parts['dbname'] ?? null) ? $parts['dbname'] : 'immobase';
        $schema = self::schemaOf($name);

        return \sprintf(
            'postgresql://%s:%s@%s:%d/%s?options=-csearch_path%%3D%s',
            rawurlencode($schema),
            rawurlencode($password),
            $address->forPlugins(),
            $address->port,
            rawurlencode($database),
            $schema,
        );
    }

    public static function schemaOf(string $name): string
    {
        return 'plugin_'.$name;
    }

    private function roleExists(string $role): bool
    {
        return (bool) $this->connection->fetchOne('SELECT count(*) FROM pg_roles WHERE rolname = ?', [$role]);
    }

    /**
     * @param array<string, array<string, true>> $existing
     *
     * @return list<string>
     */
    private function statementsFor(string $schema, TableSpec $table, array $existing): array
    {
        if (!isset($existing[$table->name])) {
            return $this->statements->create($schema, $table);
        }

        $statements = [];

        foreach ($table->columns as $column) {
            if (!isset($existing[$table->name][$column->name])) {
                $statements[] = $this->statements->addColumn($schema, $table->name, $column);
            }
        }

        return [...$statements, ...$this->statements->indexes($schema, $table)];
    }

    /**
     * Was im Schema schon steht.
     *
     * @return array<string, array<string, true>>
     */
    private function existingColumns(string $schema): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ?',
            [$schema],
        );
        $found = [];

        foreach ($rows as $row) {
            $table = $row['table_name'] ?? null;
            $column = $row['column_name'] ?? null;

            if (\is_string($table) && \is_string($column)) {
                $found[$table][$column] = true;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            try {
                $this->connection->executeStatement($statement);
            } catch (DbalException $failed) {
                throw new RuntimeException(\sprintf('Der Speicher des Plugins ließ sich nicht einrichten: %s', $failed->getMessage()), 0, $failed);
            }
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
