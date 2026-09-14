<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

/**
 * Die angemeldeten Tabellen eines Manifests.
 *
 * Eigene Klasse, weil hier am genauesten hingesehen werden muss: aus diesen
 * Namen entsteht spaeter DDL. Sie sind deshalb auf Kleinbuchstaben, Ziffern
 * und Unterstrich begrenzt — was hier durchkommt, darf ohne weitere Sorge in
 * einen CREATE-Befehl.
 */
final readonly class TableReader
{
    private const string IDENTIFIER = '/^[a-z][a-z0-9_]{0,62}$/D';

    /**
     * @return list<TableSpec>
     */
    public function from(Fields $fields): array
    {
        $found = [];

        foreach ($fields->each('tables') as $entry) {
            $found[] = new TableSpec($entry->text('name', self::IDENTIFIER), $this->columns($entry));
        }

        return $found;
    }

    /**
     * @return non-empty-list<ColumnSpec>
     */
    private function columns(Fields $table): array
    {
        $columns = [];

        foreach ($table->each('columns') as $column) {
            $type = $column->text('type');

            $columns[] = new ColumnSpec(
                $column->text('name', self::IDENTIFIER),
                ColumnType::tryFrom($type) ?? throw ManifestFault::at('columns.type', \sprintf('„%s" ist kein bekannter Typ', $type)),
                $column->flag('primary'),
                $column->flag('nullable'),
                $column->flag('index'),
            );
        }

        if ([] === $columns) {
            throw ManifestFault::at('tables.columns', 'erwartet mindestens eine Spalte');
        }

        return $columns;
    }
}
