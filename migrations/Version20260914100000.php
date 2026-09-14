<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Budgetplaene.
 *
 * Ein Plan, seine Positionen, die Zustimmungen und die eingefrorenen
 * Schreiben. Die Finanzierung steht als Spalten am Plan selbst: sie ist eine
 * Angabe und keine Liste — vier Wege, die zusammen den Bedarf decken.
 *
 * Die **Zustimmungen** haengen wie die Planquellen ueber eine Kennung am Plan
 * und nicht ueber einen Verweis auf die Entity: es ist eine Menge von
 * Einheiten, die einmal nach der Versammlung geschrieben wird.
 */
final class Version20260914100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die Budgetpläne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE billing_budget_number_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_budget (
                id UUID NOT NULL,
                number INT NOT NULL,
                iteration SMALLINT DEFAULT 1 NOT NULL,
                corrects_id UUID DEFAULT NULL,
                property_id UUID NOT NULL,
                property_number INT NOT NULL,
                label VARCHAR(200) DEFAULT '' NOT NULL,
                measure_kind VARCHAR(16) NOT NULL,
                first_year SMALLINT NOT NULL,
                amortises_in SMALLINT DEFAULT NULL,
                key_id UUID DEFAULT NULL,
                key_label VARCHAR(120) DEFAULT '' NOT NULL,
                key_kind VARCHAR(16) DEFAULT '' NOT NULL,
                from_reserve BIGINT DEFAULT 0 NOT NULL,
                levy_amount BIGINT DEFAULT 0 NOT NULL,
                levy_due_on DATE DEFAULT NULL,
                levy_parts SMALLINT DEFAULT 1 NOT NULL,
                levy_interval VARCHAR(16) NOT NULL,
                saving_amount BIGINT DEFAULT 0 NOT NULL,
                saving_years SMALLINT DEFAULT 0 NOT NULL,
                saving_from SMALLINT DEFAULT 0 NOT NULL,
                loan_amount BIGINT DEFAULT 0 NOT NULL,
                loan_rate_bps INT DEFAULT 0 NOT NULL,
                loan_payment BIGINT DEFAULT NULL,
                loan_months SMALLINT DEFAULT NULL,
                decided_on DATE DEFAULT NULL,
                decision_outcome VARCHAR(200) DEFAULT '' NOT NULL,
                decision_number VARCHAR(32) DEFAULT '' NOT NULL,
                votes_cast INT DEFAULT 0 NOT NULL,
                votes_for INT DEFAULT 0 NOT NULL,
                cost_bearing VARCHAR(24) DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                proposed_on DATE DEFAULT NULL,
                released_on DATE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_budget_number ON billing_budget (number, iteration)');
        $this->addSql('CREATE INDEX billing_budget_property ON billing_budget (property_id, first_year)');
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget
                ADD CONSTRAINT billing_budget_corrects
                FOREIGN KEY (corrects_id) REFERENCES billing_budget (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget
                ADD CONSTRAINT billing_budget_object
                FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE RESTRICT
        SQL);

        // Eine Rate oder eine Laufzeit, nicht beides: aus beidem zugleich
        // liesse sich kein Tilgungsplan rechnen, ohne eine der beiden Angaben
        // stillschweigend zu verwerfen.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget
                ADD CONSTRAINT billing_budget_loan_terms
                CHECK (loan_amount = 0 OR num_nonnulls(loan_payment, loan_months) = 1)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_budget_position (
                id UUID NOT NULL,
                budget_id UUID NOT NULL,
                ordering SMALLINT NOT NULL,
                label VARCHAR(200) DEFAULT '' NOT NULL,
                year SMALLINT NOT NULL,
                amount BIGINT DEFAULT 0 NOT NULL,
                note TEXT DEFAULT '' NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget_position
                ADD CONSTRAINT billing_budget_position_budget
                FOREIGN KEY (budget_id) REFERENCES billing_budget (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_budget_approval (
                id UUID NOT NULL,
                budget_id UUID NOT NULL,
                unit_id UUID NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX billing_budget_approval_unit
                ON billing_budget_approval (budget_id, unit_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget_approval
                ADD CONSTRAINT billing_budget_approval_budget
                FOREIGN KEY (budget_id) REFERENCES billing_budget (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget_approval
                ADD CONSTRAINT billing_budget_approval_unit_fk
                FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_budget_document (
                id UUID NOT NULL,
                budget_id UUID NOT NULL,
                unit_id UUID NOT NULL,
                unit_number INT NOT NULL,
                unit_label VARCHAR(200) NOT NULL,
                recipient_label VARCHAR(400) NOT NULL,
                recipient_address TEXT NOT NULL,
                share_amount BIGINT NOT NULL,
                levy_share BIGINT NOT NULL,
                saving_share BIGINT NOT NULL,
                loan_share BIGINT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX billing_budget_document_unit ON billing_budget_document (unit_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget_document
                ADD CONSTRAINT billing_budget_document_budget
                FOREIGN KEY (budget_id) REFERENCES billing_budget (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_budget_document
                ADD CONSTRAINT billing_budget_document_unit_fk
                FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_budget_document');
        $this->addSql('DROP TABLE billing_budget_approval');
        $this->addSql('DROP TABLE billing_budget_position');
        $this->addSql('DROP TABLE billing_budget');
        $this->addSql('DROP SEQUENCE billing_budget_number_seq');
    }
}
