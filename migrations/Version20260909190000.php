<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Einmalige Schluessel: Einladung, Zuruecksetzen, Code, Adresswechsel.
 */
final class Version20260909190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabelle auth_token für einmalige Schlüssel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE auth_token (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                hash VARCHAR(64) NOT NULL,
                payload VARCHAR(320) DEFAULT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // Gesucht wird ausschliesslich ueber den Hash — er ist das einzige,
        // was von einem Schluessel je in die Naehe der Datenbank kommt.
        $this->addSql('CREATE INDEX auth_token_lookup ON auth_token (hash)');
        $this->addSql('CREATE INDEX auth_token_owner ON auth_token (user_id, purpose)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_token');
    }
}
