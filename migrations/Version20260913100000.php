<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Vermoegensberichte.
 *
 * Ein Bericht, seine erfassten Positionen, die eingefrorenen Forderungen und
 * je Einheit ein Empfaenger. Der Ruecklagenstand steht als sechs Spalten am
 * Bericht selbst: er ist eine Angabe und keine Liste, und zum Berichtsjahr
 * gibt es genau einen.
 *
 * Anders als bei Abrechnung und Plan gibt es keine eingefrorenen Zeilen je
 * Schreiben. Der Bericht spricht ueber das Vermoegen der Gemeinschaft, und das
 * ist fuer alle Empfaenger dasselbe — die erfassten Positionen sind schon der
 * Text, und nach der Herausgabe fasst sie niemand mehr an.
 */
final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die Vermögensberichte';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE billing_asset_report_number_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_asset_report (
                id UUID NOT NULL,
                number INT NOT NULL,
                iteration SMALLINT DEFAULT 1 NOT NULL,
                corrects_id UUID DEFAULT NULL,
                property_id UUID NOT NULL,
                property_number INT NOT NULL,
                fiscal_year SMALLINT NOT NULL,
                period_from DATE NOT NULL,
                label VARCHAR(200) DEFAULT '' NOT NULL,
                status VARCHAR(16) NOT NULL,
                released_on DATE DEFAULT NULL,
                reserve_opening BIGINT DEFAULT 0 NOT NULL,
                reserve_contributions BIGINT DEFAULT 0 NOT NULL,
                reserve_levies BIGINT DEFAULT 0 NOT NULL,
                reserve_interest BIGINT DEFAULT 0 NOT NULL,
                reserve_withdrawals BIGINT DEFAULT 0 NOT NULL,
                reserve_closing BIGINT DEFAULT 0 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_asset_report_number ON billing_asset_report (number, iteration)');
        $this->addSql(<<<'SQL'
            CREATE INDEX billing_asset_report_property
                ON billing_asset_report (property_id, fiscal_year)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_asset_report
                ADD CONSTRAINT billing_asset_report_corrects
                FOREIGN KEY (corrects_id) REFERENCES billing_asset_report (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_asset_report
                ADD CONSTRAINT billing_asset_report_object
                FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE RESTRICT
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_asset_item (
                id UUID NOT NULL,
                report_id UUID NOT NULL,
                ordering SMALLINT NOT NULL,
                kind VARCHAR(16) NOT NULL,
                label VARCHAR(200) DEFAULT '' NOT NULL,
                amount BIGINT DEFAULT NULL,
                is_earmarked BOOLEAN DEFAULT false NOT NULL,
                note TEXT DEFAULT '' NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_asset_item
                ADD CONSTRAINT billing_asset_item_report
                FOREIGN KEY (report_id) REFERENCES billing_asset_report (id) ON DELETE CASCADE
        SQL);

        // Zweckgebunden ist nur ein Guthaben. Auf einem Gartengeraet liegt
        // keine Erhaltungsruecklage, und die Anwendung sagt es schon vorher —
        // das hier ist die letzte Linie.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_asset_item
                ADD CONSTRAINT billing_asset_item_earmarked
                CHECK (NOT is_earmarked OR kind = 'bank')
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_asset_claim (
                id UUID NOT NULL,
                report_id UUID NOT NULL,
                unit_number INT NOT NULL,
                amount BIGINT NOT NULL,
                since SMALLINT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_asset_claim
                ADD CONSTRAINT billing_asset_claim_report
                FOREIGN KEY (report_id) REFERENCES billing_asset_report (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_asset_report_document (
                id UUID NOT NULL,
                report_id UUID NOT NULL,
                unit_id UUID NOT NULL,
                unit_number INT NOT NULL,
                unit_label VARCHAR(200) NOT NULL,
                recipient_label VARCHAR(400) NOT NULL,
                recipient_address TEXT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX billing_asset_document_unit
                ON billing_asset_report_document (unit_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_asset_report_document
                ADD CONSTRAINT billing_asset_report_document_report
                FOREIGN KEY (report_id) REFERENCES billing_asset_report (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_asset_report_document
                ADD CONSTRAINT billing_asset_report_document_unit_fk
                FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_asset_report_document');
        $this->addSql('DROP TABLE billing_asset_claim');
        $this->addSql('DROP TABLE billing_asset_item');
        $this->addSql('DROP TABLE billing_asset_report');
        $this->addSql('DROP SEQUENCE billing_asset_report_number_seq');
    }
}
