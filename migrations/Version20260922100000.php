<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Das Aenderungsprotokoll.
 *
 * **Alles darin ist eingefroren und nichts verweist irgendwohin.** Der Name
 * des Handelnden steht als Text, die Bezeichnung des Datensatzes ebenso: ein
 * Protokoll mit Fremdschluesseln waere nach dem ersten Loeschen entweder
 * kaputt oder haette den Vorgang mitgenommen, den es festhalten soll.
 *
 * Die Kennung bleibt als Text stehen — sie ist der Faden, an dem sich zwei
 * Zeilen zu demselben Datensatz erkennen lassen, auch wenn es ihn nicht mehr
 * gibt.
 *
 * **Achtundvierzig Stunden.** Laenger haelt das Protokoll nichts; was
 * aufbewahrt werden soll, wird vorher ausgedruckt. Ein Protokoll ohne Frist
 * waechst still, bis es die Datenbank fuellt — und niemand entscheidet je,
 * wann es genug ist.
 */
final class Version20260922100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Änderungsprotokoll: wer wann was geschrieben, gelöscht oder sich angemeldet hat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_entry (
            id UUID NOT NULL,
            at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            action VARCHAR(16) NOT NULL,
            actor VARCHAR(200) NOT NULL,
            actor_kind VARCHAR(16) NOT NULL,
            record VARCHAR(64) NOT NULL,
            record_id VARCHAR(64) NOT NULL,
            label VARCHAR(300) NOT NULL,
            PRIMARY KEY (id)
        )');

        // Gelesen und weggeraeumt wird immer nach der Zeit.
        $this->addSql('CREATE INDEX audit_entry_when ON audit_entry (at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_entry');
    }
}
