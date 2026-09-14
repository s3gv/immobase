<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jahreswerte und erfasste Verbraeuche.
 *
 * Das laufende Jahr wird erfasst, das vergangene abgerechnet — deshalb je
 * Jahr ein Wert und nicht einer an der Position.
 */
final class Version20260911000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jahreswerte und Verbräuche der Kostenpositionen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_cost_item_year (
                id UUID NOT NULL,
                cost_item_id UUID NOT NULL,
                fiscal_year SMALLINT NOT NULL,
                amount BIGINT NOT NULL,
                entry_mode VARCHAR(16) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT finance_year_item FOREIGN KEY (cost_item_id)
                    REFERENCES finance_cost_item (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX finance_cost_item_year_once ON finance_cost_item_year (cost_item_id, fiscal_year)',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE finance_cost_item_year_unit (
                id UUID NOT NULL,
                year_id UUID NOT NULL,
                unit_id UUID NOT NULL,
                consumption NUMERIC(14, 3) NOT NULL,
                amount BIGINT DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT finance_year_unit_year FOREIGN KEY (year_id)
                    REFERENCES finance_cost_item_year (id) ON DELETE CASCADE,
                -- RESTRICT: was eine Abrechnung braucht, verschwindet nicht
                -- nebenbei mit der Einheit.
                CONSTRAINT finance_year_unit_unit FOREIGN KEY (unit_id)
                    REFERENCES property_unit (id) ON DELETE RESTRICT
            )
        SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX finance_year_unit_once ON finance_cost_item_year_unit (year_id, unit_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_cost_item_year_unit');
        $this->addSql('DROP TABLE finance_cost_item_year');
    }
}
