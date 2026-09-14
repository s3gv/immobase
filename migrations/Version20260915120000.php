<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Darlehen der Gemeinschaft.
 *
 * Gespeichert wird, was vereinbart ist: Summe, Zins, erste Rate — und
 * **entweder** die Rate **oder** die Laufzeit. Beides zugleich waeren zwei
 * Angaben, die sich widersprechen koennen; die Pruefbedingung besteht darauf,
 * dass genau eine dasteht. Dieselbe Bedingung steht schon am Budgetplan.
 *
 * Keine Restschuld und kein Tilgungsplan: beides ist eine Rechnung ueber
 * diese Angaben und die Ereignisse. Eine gespeicherte Restschuld waere eine
 * Zahl, die jemand jaehrlich nachpflegen muss.
 *
 * Die Ereignisse sind die Sondertilgung und der neue Zins. Die Abloesung ist
 * keine eigene Art: sie ist eine Sondertilgung ueber den Rest.
 */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die Darlehen der Gemeinschaft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE finance_loan_number_seq INCREMENT BY 1 MINVALUE 50001 START 50001');

        $this->addSql(<<<'SQL'
            CREATE TABLE finance_loan (
                id UUID NOT NULL,
                number INT NOT NULL,
                property_id UUID NOT NULL,
                label VARCHAR(200) DEFAULT '' NOT NULL,
                lender VARCHAR(200) DEFAULT '' NOT NULL,
                loan_amount BIGINT NOT NULL,
                rate_bps INT NOT NULL,
                starts_on DATE NOT NULL,
                payment BIGINT DEFAULT NULL,
                months SMALLINT DEFAULT NULL,
                reference VARCHAR(40) DEFAULT '' NOT NULL,
                note TEXT DEFAULT '' NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT finance_loan_terms CHECK (num_nonnulls(payment, months) = 1)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX finance_loan_number ON finance_loan (number)');
        $this->addSql('CREATE INDEX finance_loan_property ON finance_loan (property_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE finance_loan
                ADD CONSTRAINT finance_loan_property_fk FOREIGN KEY (property_id)
                REFERENCES property (id) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE finance_loan_event (
                id UUID NOT NULL,
                loan_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                occurred_on DATE NOT NULL,
                amount BIGINT DEFAULT NULL,
                rate_bps INT DEFAULT NULL,
                note TEXT DEFAULT '' NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX finance_loan_event_loan ON finance_loan_event (loan_id, occurred_on)');
        $this->addSql(<<<'SQL'
            ALTER TABLE finance_loan_event
                ADD CONSTRAINT finance_loan_event_loan_fk FOREIGN KEY (loan_id)
                REFERENCES finance_loan (id) ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_loan_event');
        $this->addSql('DROP TABLE finance_loan');
        $this->addSql('DROP SEQUENCE finance_loan_number_seq');
    }
}
