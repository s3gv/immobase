<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mietverhaeltnisse: wer wohnt in einer Einheit, seit wann, zu welchem Preis.
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mietverhältnisse, Mieter und Mietstaffel';
    }

    public function up(Schema $schema): void
    {
        // Eigener Zahlenraum je Datenart: Stammdaten ab 10001, Objekte ab
        // 20001, Mietverhaeltnisse ab 30001. Eine Sequenz und nicht
        // MAX(number) + 1 — zwei gleichzeitige Anlagen lesen sonst denselben
        // Hoechststand, und die zweite laeuft in den eindeutigen Index.
        $this->addSql('CREATE SEQUENCE tenancy_number_seq START WITH 30001 INCREMENT BY 1 MINVALUE 30001');

        $this->addSql(<<<'SQL'
            CREATE TABLE tenancy (
                id UUID NOT NULL,
                number INT NOT NULL,
                unit_id UUID NOT NULL,
                status VARCHAR(16) NOT NULL,
                starts_on DATE DEFAULT NULL,
                ends_on DATE DEFAULT NULL,
                handed_over_on DATE DEFAULT NULL,
                notice_period_months SMALLINT DEFAULT NULL,
                household_size SMALLINT DEFAULT NULL,
                payment_method VARCHAR(16) NOT NULL,
                payment_due VARCHAR(24) NOT NULL,
                deposit_amount BIGINT DEFAULT NULL,
                deposit_kind VARCHAR(16) DEFAULT NULL,
                deposit_received_on DATE DEFAULT NULL,
                note TEXT NOT NULL,
                PRIMARY KEY(id),
                -- Die Einheit liegt in einem anderen Modul, und trotzdem
                -- gehoert der Verweis hierher. Die Anwendung schlaegt jede
                -- Kennung nach, bevor sie sie uebernimmt, aber zwischen
                -- Nachschlagen und Speichern passt ein Loeschvorgang.
                -- RESTRICT: eine vermietete Einheit verschwindet nicht
                -- nebenbei — auch nicht die Geschichte einer beendeten Miete.
                CONSTRAINT tenancy_unit FOREIGN KEY (unit_id)
                    REFERENCES property_unit (id) ON DELETE RESTRICT
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX tenancy_number ON tenancy (number)');
        $this->addSql('CREATE INDEX tenancy_unit_idx ON tenancy (unit_id)');

        // Hoechstens ein aktives Mietverhaeltnis je Einheit. Leerstand wird
        // nicht erfasst, sondern erschlossen — eine Einheit ohne aktives
        // Mietverhaeltnis steht leer. Das traegt nur, wenn „aktiv" je Einheit
        // eindeutig ist, und dafuer reicht keine Pruefung in der Anwendung:
        // zwei gleichzeitige Anfragen sehen beide keine.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX tenancy_one_active_per_unit
                ON tenancy (unit_id) WHERE status = 'active'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tenancy_tenant (
                id UUID NOT NULL,
                tenancy_id UUID NOT NULL,
                party_id UUID NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT tenancy_tenant_tenancy FOREIGN KEY (tenancy_id)
                    REFERENCES tenancy (id) ON DELETE CASCADE,
                -- Wie beim Eigentum: wer mietet, dessen Stammdatensatz
                -- verschwindet nicht nebenbei.
                CONSTRAINT tenancy_tenant_party FOREIGN KEY (party_id)
                    REFERENCES party (id) ON DELETE RESTRICT
            )
        SQL);
        // Derselbe Kontakt steht nur einmal an demselben Mietverhaeltnis.
        $this->addSql('CREATE UNIQUE INDEX tenancy_tenant_once ON tenancy_tenant (tenancy_id, party_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE tenancy_rent (
                id UUID NOT NULL,
                tenancy_id UUID NOT NULL,
                starts_on DATE NOT NULL,
                base_rent BIGINT NOT NULL,
                operating_costs BIGINT NOT NULL,
                heating BIGINT NOT NULL,
                parking BIGINT NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT tenancy_rent_tenancy FOREIGN KEY (tenancy_id)
                    REFERENCES tenancy (id) ON DELETE CASCADE
            )
        SQL);
        // Zu einem Tag hoechstens eine Stufe: sonst waere nicht entscheidbar,
        // welche gilt.
        $this->addSql('CREATE UNIQUE INDEX tenancy_rent_once ON tenancy_rent (tenancy_id, starts_on)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenancy_rent');
        $this->addSql('DROP TABLE tenancy_tenant');
        $this->addSql('DROP TABLE tenancy');
        $this->addSql('DROP SEQUENCE tenancy_number_seq');
    }
}
