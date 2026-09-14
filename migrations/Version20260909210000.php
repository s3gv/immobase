<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zweiter Faktor und Wiederherstellungscodes.
 */
final class Version20260909210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zweiter Faktor am Konto, Tabelle auth_recovery_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE auth_user ADD factor_kind VARCHAR(16) NOT NULL DEFAULT 'none'");
        $this->addSql('ALTER TABLE auth_user ADD factor_secret VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE auth_user ADD factor_used_step BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE auth_user ALTER COLUMN factor_kind DROP DEFAULT');

        $this->addSql(<<<'SQL'
            CREATE TABLE auth_recovery_code (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                hash VARCHAR(64) NOT NULL,
                used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX auth_recovery_code_owner ON auth_recovery_code (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE auth_recovery_code');
        $this->addSql('ALTER TABLE auth_user DROP factor_kind');
        $this->addSql('ALTER TABLE auth_user DROP factor_secret');
        $this->addSql('ALTER TABLE auth_user DROP factor_used_step');
    }
}
