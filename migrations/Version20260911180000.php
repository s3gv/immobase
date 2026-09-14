<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Abrechnungen.
 *
 * Ein Lauf, seine Dokumente, deren Zeilen — und die Quellen, aus denen er
 * entstanden ist. Die Quellen sind der Grund, warum eine Kostenposition, auf
 * der eine Abrechnung steht, nicht mehr verschwindet: der Fremdschluessel
 * darauf steht auf RESTRICT.
 *
 * Alles Uebrige haengt am Lauf und geht mit ihm — aber nur ein Entwurf laesst
 * sich ueberhaupt loeschen.
 */
final class Version20260911180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die Abrechnungen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE billing_statement_number_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_statement (
                id UUID NOT NULL,
                number INT NOT NULL,
                iteration SMALLINT DEFAULT 1 NOT NULL,
                corrects_id UUID DEFAULT NULL,
                property_id UUID NOT NULL,
                property_number INT NOT NULL,
                fiscal_year SMALLINT NOT NULL,
                label VARCHAR(200) DEFAULT '' NOT NULL,
                for_owners BOOLEAN DEFAULT true NOT NULL,
                for_tenants BOOLEAN DEFAULT true NOT NULL,
                status VARCHAR(16) NOT NULL,
                released_on DATE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_statement_number ON billing_statement (number, iteration)');
        $this->addSql('CREATE INDEX billing_statement_property ON billing_statement (property_id, fiscal_year)');
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_statement
                ADD CONSTRAINT billing_statement_corrects
                FOREIGN KEY (corrects_id) REFERENCES billing_statement (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_statement
                ADD CONSTRAINT billing_statement_object
                FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE RESTRICT
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_document (
                id UUID NOT NULL,
                statement_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                unit_id UUID NOT NULL,
                unit_number INT NOT NULL,
                unit_label VARCHAR(200) NOT NULL,
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                recipient_label VARCHAR(400) NOT NULL,
                recipient_address TEXT NOT NULL,
                costs BIGINT DEFAULT 0 NOT NULL,
                advances BIGINT DEFAULT 0 NOT NULL,
                wants_pdf BOOLEAN DEFAULT true NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX billing_document_unit ON billing_document (unit_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_document
                ADD CONSTRAINT billing_document_statement
                FOREIGN KEY (statement_id) REFERENCES billing_statement (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_document
                ADD CONSTRAINT billing_document_unit_fk
                FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_line (
                id UUID NOT NULL,
                document_id UUID NOT NULL,
                position SMALLINT NOT NULL,
                cost_kind_label VARCHAR(200) NOT NULL,
                key_label VARCHAR(120) NOT NULL,
                key_explanation VARCHAR(400) NOT NULL,
                share_of NUMERIC(14, 3) NOT NULL,
                share_total NUMERIC(14, 3) NOT NULL,
                total_quantity NUMERIC(14, 3) DEFAULT NULL,
                unit_of_measure VARCHAR(8) DEFAULT NULL,
                days_of SMALLINT DEFAULT NULL,
                days_total SMALLINT DEFAULT NULL,
                total_amount BIGINT NOT NULL,
                amount BIGINT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_line
                ADD CONSTRAINT billing_line_document
                FOREIGN KEY (document_id) REFERENCES billing_document (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_advance (
                id UUID NOT NULL,
                document_id UUID NOT NULL,
                due_on DATE NOT NULL,
                expected BIGINT NOT NULL,
                received BIGINT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_advance
                ADD CONSTRAINT billing_advance_document
                FOREIGN KEY (document_id) REFERENCES billing_document (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_source (
                id UUID NOT NULL,
                statement_id UUID NOT NULL,
                cost_year_id UUID DEFAULT NULL,
                payment_id UUID DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_source_cost ON billing_source (statement_id, cost_year_id)');
        $this->addSql('CREATE UNIQUE INDEX billing_source_payment ON billing_source (statement_id, payment_id)');

        // Genau eine Quelle je Zeile — beides oder keines waere keine Angabe.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_source
                ADD CONSTRAINT billing_source_one
                CHECK (num_nonnulls(cost_year_id, payment_id) = 1)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE billing_source
                ADD CONSTRAINT billing_source_statement
                FOREIGN KEY (statement_id) REFERENCES billing_statement (id) ON DELETE CASCADE
        SQL);

        // Die beiden Linien, auf die es ankommt: was in eine Abrechnung
        // eingegangen ist, laesst sich nicht mehr loeschen.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_source
                ADD CONSTRAINT billing_source_cost_year
                FOREIGN KEY (cost_year_id) REFERENCES finance_cost_item_year (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_source
                ADD CONSTRAINT billing_source_payment_fk
                FOREIGN KEY (payment_id) REFERENCES finance_advance_payment (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_source');
        $this->addSql('DROP TABLE billing_advance');
        $this->addSql('DROP TABLE billing_line');
        $this->addSql('DROP TABLE billing_document');
        $this->addSql('DROP TABLE billing_statement');
        $this->addSql('DROP SEQUENCE billing_statement_number_seq');
    }
}
