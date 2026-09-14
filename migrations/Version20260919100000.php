<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anfragen: Gespraech, Nachricht, Anhang.
 *
 * **Die Anhaenge liegen in der Zeile und nicht auf einer Platte.** Der Grund
 * ist das Loeschen: liegen die Bytes im Dateisystem, gibt es drei Wege, auf
 * denen doch etwas stehen bleibt — der Aufraeumer laeuft nicht, ein Absturz
 * zwischen Zeile und `unlink`, ein abgebrochener Upload. Liegen sie hier, ist
 * Loeschen dasselbe wie Vergessen.
 *
 * **Verschluesselt**, damit ein Abzug nichts Lesbares enthaelt. Text und
 * nicht bytea: was kodiert wird, ist bereits Geheimtext, und eine
 * Binaerspalte gaebe beim Lesen einen Datenstrom zurueck.
 *
 * **Kaskaden nach unten, kein Fremdschluessel auf die Partei.** Eine Anfrage
 * nimmt ihre Nachrichten mit, eine Nachricht ihre Anhaenge. Was passiert,
 * wenn eine Partei verschwindet, entscheidet dagegen das Stammdatenmodul —
 * sichtbar im Code und nicht still in der Datenbank.
 */
final class Version20260919100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anfragen im Mieterportal — mit verschlüsselten Anhängen auf Zeit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE portal_enquiry_number_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE portal_enquiry (
            id UUID NOT NULL,
            number INT NOT NULL,
            party_id UUID NOT NULL,
            subject VARCHAR(200) NOT NULL,
            state VARCHAR(16) NOT NULL,
            assignee_user_id UUID DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_message_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            first_answer_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            read_by_staff_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            read_by_party_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            notify_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT portal_enquiry_number UNIQUE (number)
        )');
        $this->addSql('CREATE INDEX portal_enquiry_party ON portal_enquiry (party_id)');
        // Der Aufraeumlauf fragt danach, und er laeuft in Schleife.
        $this->addSql('CREATE INDEX portal_enquiry_due ON portal_enquiry (notify_due_at)');

        $this->addSql('CREATE TABLE portal_message (
            id UUID NOT NULL,
            enquiry_id UUID NOT NULL,
            author_user_id UUID DEFAULT NULL,
            author_label VARCHAR(400) NOT NULL,
            body TEXT NOT NULL,
            written_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX portal_message_enquiry ON portal_message (enquiry_id)');
        $this->addSql('ALTER TABLE portal_message
            ADD CONSTRAINT portal_message_enquiry_fk FOREIGN KEY (enquiry_id)
            REFERENCES portal_enquiry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE portal_attachment (
            id UUID NOT NULL,
            message_id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            media_type VARCHAR(120) NOT NULL,
            size_bytes INT NOT NULL,
            nonce TEXT NOT NULL,
            cipher TEXT NOT NULL,
            delete_after TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX portal_attachment_message ON portal_attachment (message_id)');
        // Danach fragt der Aufraeumlauf, und zwar nach jeder abgelaufenen.
        $this->addSql('CREATE INDEX portal_attachment_expiry ON portal_attachment (delete_after)');
        $this->addSql('ALTER TABLE portal_attachment
            ADD CONSTRAINT portal_attachment_message_fk FOREIGN KEY (message_id)
            REFERENCES portal_message (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portal_attachment DROP CONSTRAINT portal_attachment_message_fk');
        $this->addSql('ALTER TABLE portal_message DROP CONSTRAINT portal_message_enquiry_fk');
        $this->addSql('DROP TABLE portal_attachment');
        $this->addSql('DROP TABLE portal_message');
        $this->addSql('DROP TABLE portal_enquiry');
        $this->addSql('DROP SEQUENCE portal_enquiry_number_seq');
    }
}
