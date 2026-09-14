<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\Manifest\ColumnSpec;
use App\Module\Plugin\Domain\Manifest\TableSpec;

/**
 * Aus einer angemeldeten Tabelle wird DDL.
 *
 * Alle Namen, die hier ankommen, sind beim Lesen des Manifests gegen
 * `^[a-z][a-z0-9_]{0,62}$` geprueft worden — anders kaemen sie nicht bis
 * hierher. Trotzdem stehen sie in Anfuehrungszeichen: die Pruefung ist die
 * Zusicherung, die Anfuehrungszeichen sind der zweite Boden.
 */
final readonly class TableStatements
{
    /**
     * @return non-empty-list<string>
     */
    public function create(string $schema, TableSpec $table): array
    {
        $columns = [];
        $keys = [];

        foreach ($table->columns as $column) {
            $columns[] = $this->column($column);

            if ($column->primary) {
                $keys[] = $this->quote($column->name);
            }
        }

        if ([] !== $keys) {
            $columns[] = 'PRIMARY KEY ('.implode(', ', $keys).')';
        }

        $create = \sprintf('CREATE TABLE %s (%s)', $this->name($schema, $table->name), implode(', ', $columns));

        return [$create, ...$this->indexes($schema, $table)];
    }

    /**
     * @return list<string>
     */
    public function indexes(string $schema, TableSpec $table): array
    {
        $statements = [];

        foreach ($table->columns as $column) {
            if (!$column->index || $column->primary) {
                continue;
            }

            $statements[] = \sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                $this->quote($table->name.'_'.$column->name.'_idx'),
                $this->name($schema, $table->name),
                $this->quote($column->name),
            );
        }

        return $statements;
    }

    public function addColumn(string $schema, string $table, ColumnSpec $column): string
    {
        // Ohne Vorgabe und immer erlaubend: eine neue Spalte in einer
        // Tabelle, in der schon Zeilen stehen, kann nicht zugleich neu und
        // pflichtig sein.
        return \sprintf(
            'ALTER TABLE %s ADD COLUMN IF NOT EXISTS %s %s',
            $this->name($schema, $table),
            $this->quote($column->name),
            $column->type->sql(),
        );
    }

    private function column(ColumnSpec $column): string
    {
        return $this->quote($column->name).' '.$column->type->sql().($column->nullable ? '' : ' NOT NULL');
    }

    private function name(string $schema, string $table): string
    {
        return $this->quote($schema).'.'.$this->quote($table);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
