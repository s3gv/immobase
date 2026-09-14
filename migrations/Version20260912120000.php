<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Wirtschaftsplaene.
 *
 * Ein Plan, seine Zeilen, die eingefrorenen Schreiben je Einheit und deren
 * Zeilen — und die Quellen, aus denen er entstanden ist. Dieselbe Bauart wie
 * bei den Abrechnungen, und aus denselben Gruenden: der Fremdschluessel auf
 * eine Quelle steht auf RESTRICT, alles Uebrige haengt am Plan und geht mit
 * ihm.
 *
 * Anders als dort gibt es keine Zahlungszeilen. Ein Plan sagt, was zu zahlen
 * sein wird; was gezahlt wurde, steht in den Finanzen.
 */
final class Version20260912120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die Wirtschaftspläne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE billing_plan_number_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_plan (
                id UUID NOT NULL,
                number INT NOT NULL,
                iteration SMALLINT DEFAULT 1 NOT NULL,
                corrects_id UUID DEFAULT NULL,
                property_id UUID NOT NULL,
                property_number INT NOT NULL,
                fiscal_year SMALLINT NOT NULL,
                period_from DATE NOT NULL,
                label VARCHAR(200) DEFAULT '' NOT NULL,
                pay_interval VARCHAR(16) NOT NULL,
                first_due_on DATE NOT NULL,
                decided_on DATE DEFAULT NULL,
                decision_outcome VARCHAR(200) DEFAULT '' NOT NULL,
                decision_number VARCHAR(32) DEFAULT '' NOT NULL,
                status VARCHAR(16) NOT NULL,
                released_on DATE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_plan_number ON billing_plan (number, iteration)');
        $this->addSql('CREATE INDEX billing_plan_property ON billing_plan (property_id, fiscal_year)');
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan
                ADD CONSTRAINT billing_plan_corrects
                FOREIGN KEY (corrects_id) REFERENCES billing_plan (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan
                ADD CONSTRAINT billing_plan_object
                FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE RESTRICT
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_plan_position (
                id UUID NOT NULL,
                plan_id UUID NOT NULL,
                ordering SMALLINT NOT NULL,
                line_kind VARCHAR(16) NOT NULL,
                cost_kind_id UUID DEFAULT NULL,
                cost_kind_label VARCHAR(200) DEFAULT '' NOT NULL,
                apportionable BOOLEAN DEFAULT false NOT NULL,
                key_id UUID DEFAULT NULL,
                key_label VARCHAR(120) DEFAULT '' NOT NULL,
                key_kind VARCHAR(16) DEFAULT '' NOT NULL,
                previous_amount BIGINT DEFAULT 0 NOT NULL,
                previous_source_id UUID DEFAULT NULL,
                planned_amount BIGINT DEFAULT 0 NOT NULL,
                is_one_off BOOLEAN DEFAULT false NOT NULL,
                reason TEXT DEFAULT '' NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_position
                ADD CONSTRAINT billing_plan_position_plan
                FOREIGN KEY (plan_id) REFERENCES billing_plan (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_plan_document (
                id UUID NOT NULL,
                plan_id UUID NOT NULL,
                unit_id UUID NOT NULL,
                unit_number INT NOT NULL,
                unit_label VARCHAR(200) NOT NULL,
                recipient_label VARCHAR(400) NOT NULL,
                recipient_address TEXT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX billing_plan_document_unit ON billing_plan_document (unit_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_document
                ADD CONSTRAINT billing_plan_document_plan
                FOREIGN KEY (plan_id) REFERENCES billing_plan (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_document
                ADD CONSTRAINT billing_plan_document_unit_fk
                FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_plan_line (
                id UUID NOT NULL,
                document_id UUID NOT NULL,
                position SMALLINT NOT NULL,
                line_kind VARCHAR(16) NOT NULL,
                cost_kind_label VARCHAR(200) NOT NULL,
                key_label VARCHAR(120) NOT NULL,
                key_explanation VARCHAR(400) NOT NULL,
                share_of VARCHAR(32) NOT NULL,
                share_total VARCHAR(32) NOT NULL,
                total_quantity VARCHAR(32) DEFAULT NULL,
                unit_of_measure VARCHAR(8) DEFAULT NULL,
                days_of SMALLINT DEFAULT NULL,
                days_total SMALLINT DEFAULT NULL,
                previous_amount BIGINT NOT NULL,
                total_amount BIGINT NOT NULL,
                amount BIGINT NOT NULL,
                reason TEXT DEFAULT '' NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_line
                ADD CONSTRAINT billing_plan_line_document
                FOREIGN KEY (document_id) REFERENCES billing_plan_document (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_plan_source (
                id UUID NOT NULL,
                plan_id UUID NOT NULL,
                key_id UUID DEFAULT NULL,
                cost_kind_id UUID DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_plan_source_key ON billing_plan_source (plan_id, key_id)');
        $this->addSql('CREATE UNIQUE INDEX billing_plan_source_kind ON billing_plan_source (plan_id, cost_kind_id)');

        // Genau eine Quelle je Zeile — beides oder keines waere keine Angabe.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_source
                ADD CONSTRAINT billing_plan_source_one
                CHECK (num_nonnulls(key_id, cost_kind_id) = 1)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_source
                ADD CONSTRAINT billing_plan_source_plan
                FOREIGN KEY (plan_id) REFERENCES billing_plan (id) ON DELETE CASCADE
        SQL);

        // Die beiden Linien, auf die es ankommt: was in einen Wirtschaftsplan
        // eingegangen ist, laesst sich nicht mehr loeschen.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_source
                ADD CONSTRAINT billing_plan_source_key_fk
                FOREIGN KEY (key_id) REFERENCES finance_distribution_key (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_plan_source
                ADD CONSTRAINT billing_plan_source_kind_fk
                FOREIGN KEY (cost_kind_id) REFERENCES finance_cost_kind (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_plan_source');
        $this->addSql('DROP TABLE billing_plan_line');
        $this->addSql('DROP TABLE billing_plan_document');
        $this->addSql('DROP TABLE billing_plan_position');
        $this->addSql('DROP TABLE billing_plan');
        $this->addSql('DROP SEQUENCE billing_plan_number_seq');
    }
}
