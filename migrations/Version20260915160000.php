<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Darlehen im Vermoegensbericht — eingefroren.
 *
 * Die Restschuld wird gerechnet und nicht erfasst: sie steht im Tilgungsplan
 * der Finanzen. Mit der Herausgabe wandert sie hierher, wie die offenen
 * Hausgelder nebenan — danach aendert eine Sondertilgung das zugestellte
 * Schreiben nicht mehr.
 */
final class Version20260915160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die Darlehen im Vermögensbericht';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_asset_debt (
                id UUID NOT NULL,
                report_id UUID NOT NULL,
                label VARCHAR(400) NOT NULL,
                outstanding BIGINT NOT NULL,
                ordering SMALLINT NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT billing_asset_debt_report FOREIGN KEY (report_id)
                    REFERENCES billing_asset_report (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX billing_asset_debt_report_idx ON billing_asset_debt (report_id, ordering)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_asset_debt');
    }
}
