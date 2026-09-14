<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

/**
 * Die Spaltentypen, die ein Plugin anmelden darf.
 *
 * **Bewusst wenige.** Jeder Typ hier ist eine Zusage: er muss in jeder
 * kuenftigen Fassung dieselbe Spalte ergeben. Ein Manifest mit rohem SQL
 * waere maechtiger und nicht mehr pruefbar — was ein Plugin anlegt, soll
 * jemand vor dem Aktivieren lesen koennen.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Timestamp = 'timestamp';
    case Uuid = 'uuid';

    /** Was daraus in PostgreSQL wird. */
    public function sql(): string
    {
        return match ($this) {
            self::Text => 'TEXT',
            self::Integer => 'BIGINT',
            // Genug fuer Geld und fuer Anteile, und exakt: Auswertungen, die
            // mit Gleitkomma rechnen, gehen nicht auf.
            self::Decimal => 'NUMERIC(20, 6)',
            self::Boolean => 'BOOLEAN',
            self::Timestamp => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            self::Uuid => 'UUID',
        };
    }
}
