<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wer kein PDF bekommen soll.
 *
 * Nur die Ausnahmen: die Vorgabe ist, dass jeder Empfaenger eines bekommt.
 * Eine Zeile je Abwahl und keine Liste in einer Spalte — die Auswahl faellt im
 * Entwurf, die Dokumente entstehen erst bei der Freigabe, und dazwischen muss
 * sie irgendwo liegen.
 */
final class Version20260911200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wer kein PDF bekommen soll';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_no_pdf (
                id UUID NOT NULL,
                statement_id UUID NOT NULL,
                recipient_key VARCHAR(120) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_no_pdf_one ON billing_no_pdf (statement_id, recipient_key)');
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_no_pdf
                ADD CONSTRAINT billing_no_pdf_statement
                FOREIGN KEY (statement_id) REFERENCES billing_statement (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_no_pdf');
    }
}
