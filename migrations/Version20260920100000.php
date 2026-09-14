<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aenderungsvorschlaege aus dem Portal.
 *
 * **Ein Vorschlag haengt an einer Anfrage** — daher der eindeutige
 * Fremdschluessel und die Kaskade: verschwindet das Gespraech, verschwindet
 * der Vorschlag mit. Ein Vorschlag ohne das Gespraech, in dem er besprochen
 * wurde, waere Aktenmuell.
 *
 * **Kein Fremdschluessel auf den betroffenen Datensatz.** Woran ein Vorschlag
 * haengt, entscheidet das besitzende Modul, und das Portal kennt keines von
 * ihnen; ein Verweis in der Datenbank waere eine Abhaengigkeit, die im Code
 * ausdruecklich nicht besteht. Ist der Datensatz weg, faellt das beim
 * Freigeben auf — und nicht erst beim Schreiben.
 *
 * **Der bisherige Wert steht in der Zeile.** Er ist der Stand, auf den sich
 * der Vorschlag bezieht; nachgeschlagen waere er immer der heutige, und
 * genau der Unterschied ist die Auskunft.
 */
final class Version20260920100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Änderungsvorschläge aus dem Mieterportal — mit dem Stand, auf den sie sich beziehen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE portal_proposal (
            id UUID NOT NULL,
            enquiry_id UUID NOT NULL,
            record_kind VARCHAR(16) NOT NULL,
            record_id UUID NOT NULL,
            proposed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            decision VARCHAR(16) NOT NULL,
            decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            decided_by_user_id UUID DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT portal_proposal_enquiry UNIQUE (enquiry_id)
        )');
        $this->addSql('CREATE INDEX portal_proposal_record ON portal_proposal (record_kind, record_id)');
        $this->addSql('ALTER TABLE portal_proposal
            ADD CONSTRAINT portal_proposal_enquiry_fk FOREIGN KEY (enquiry_id)
            REFERENCES portal_enquiry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE portal_proposed_field (
            id UUID NOT NULL,
            proposal_id UUID NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            label_key VARCHAR(200) NOT NULL,
            was_value TEXT NOT NULL,
            wanted_value TEXT NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX portal_proposed_field_proposal ON portal_proposed_field (proposal_id)');
        $this->addSql('ALTER TABLE portal_proposed_field
            ADD CONSTRAINT portal_proposed_field_proposal_fk FOREIGN KEY (proposal_id)
            REFERENCES portal_proposal (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portal_proposed_field DROP CONSTRAINT portal_proposed_field_proposal_fk');
        $this->addSql('ALTER TABLE portal_proposal DROP CONSTRAINT portal_proposal_enquiry_fk');
        $this->addSql('DROP TABLE portal_proposed_field');
        $this->addSql('DROP TABLE portal_proposal');
    }
}
