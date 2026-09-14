<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kostenpositionen: was ein Objekt kostet und wie es sich verteilt.
 */
final class Version20260910220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kostenpositionen';
    }

    public function up(Schema $schema): void
    {
        // Eigener Zahlenraum je Datenart: Stammdaten ab 10001, Objekte ab
        // 20001, Mietverhältnisse ab 30001, Kostenpositionen ab 40001.
        $this->addSql(
            'CREATE SEQUENCE finance_cost_item_number_seq START WITH 40001 INCREMENT BY 1 MINVALUE 40001',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE finance_cost_item (
                id UUID NOT NULL,
                number INT NOT NULL,
                property_id UUID NOT NULL,
                cost_kind_id UUID NOT NULL,
                distribution_key_id UUID NOT NULL,
                apportionable BOOLEAN DEFAULT NULL,
                due_day SMALLINT NOT NULL,
                due_month SMALLINT DEFAULT NULL,
                due_interval VARCHAR(16) NOT NULL,
                splits_by_day BOOLEAN NOT NULL,
                note TEXT NOT NULL,
                PRIMARY KEY(id),
                -- RESTRICT wie überall dort, wo eine Abrechnung später
                -- darauf zurückgreift: das Objekt wird abgewickelt, nicht
                -- gelöscht, und die Position bleibt lesbar.
                CONSTRAINT finance_cost_item_property FOREIGN KEY (property_id)
                    REFERENCES property (id) ON DELETE RESTRICT,
                CONSTRAINT finance_cost_item_kind FOREIGN KEY (cost_kind_id)
                    REFERENCES finance_cost_kind (id) ON DELETE RESTRICT,
                CONSTRAINT finance_cost_item_key FOREIGN KEY (distribution_key_id)
                    REFERENCES finance_distribution_key (id) ON DELETE RESTRICT
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX finance_cost_item_number ON finance_cost_item (number)');
        $this->addSql('CREATE INDEX finance_cost_item_property ON finance_cost_item (property_id)');
        $this->addSql('CREATE INDEX finance_cost_item_kind ON finance_cost_item (cost_kind_id)');
        $this->addSql('CREATE INDEX finance_cost_item_key ON finance_cost_item (distribution_key_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_cost_item');
        $this->addSql('DROP SEQUENCE finance_cost_item_number_seq');
    }
}
