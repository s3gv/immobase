<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Konten bekommen Nummer, Zustand und Namen.
 *
 * Von Hand geschrieben: der Entwurf aus doctrine:migrations:diff kennt weder
 * die Sequenz noch den Zustand, in den vorhandene Konten gehoeren. Sie sind in
 * Benutzung — sie sind aktiv, nicht eingeladen.
 */
final class Version20260909160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Benutzerkonten: Nummer, Zustand, Name, Administratorkennzeichen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE auth_user_number_seq INCREMENT BY 1 MINVALUE 1001 START WITH 1001');

        $this->addSql('ALTER TABLE auth_user ADD number INT');
        $this->addSql("ALTER TABLE auth_user ADD status VARCHAR(16) NOT NULL DEFAULT 'active'");
        $this->addSql("ALTER TABLE auth_user ADD given_name VARCHAR(100) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE auth_user ADD family_name VARCHAR(100) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE auth_user ADD job_title VARCHAR(100) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE auth_user ADD last_sign_in_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE auth_user ADD administrator BOOLEAN NOT NULL DEFAULT FALSE');

        // Vorhandene Konten sind in Benutzung: sie bekommen Nummern in der
        // Reihenfolge, in der sie angelegt wurden, und behalten ihre Rechte.
        $this->addSql("UPDATE auth_user SET administrator = TRUE WHERE extra_roles::text LIKE '%ROLE_ADMIN%'");
        $this->addSql("UPDATE auth_user SET number = nextval('auth_user_number_seq')");

        // Der bisherige Anzeigename war ein einzelnes Feld. Was darin steht,
        // ist bestenfalls "Vorname Nachname" — der Rest wandert in den
        // Nachnamen, weil ein Konto ohne Nachnamen nicht anzeigbar waere.
        $this->addSql(<<<'SQL'
            UPDATE auth_user SET
                given_name  = CASE WHEN position(' ' in display_name) > 0
                                   THEN split_part(display_name, ' ', 1) ELSE '' END,
                family_name = CASE WHEN position(' ' in display_name) > 0
                                   THEN substr(display_name, position(' ' in display_name) + 1)
                                   ELSE display_name END
        SQL);

        $this->addSql('ALTER TABLE auth_user ALTER COLUMN number SET NOT NULL');
        $this->addSql('ALTER TABLE auth_user ALTER COLUMN status DROP DEFAULT');
        $this->addSql('ALTER TABLE auth_user ALTER COLUMN administrator DROP DEFAULT');
        $this->addSql('ALTER TABLE auth_user ALTER COLUMN password DROP NOT NULL');

        $this->addSql('ALTER TABLE auth_user DROP display_name');
        $this->addSql('ALTER TABLE auth_user DROP extra_roles');

        $this->addSql('CREATE UNIQUE INDEX auth_user_number ON auth_user (number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX auth_user_number');
        $this->addSql("ALTER TABLE auth_user ADD display_name VARCHAR(200) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE auth_user ADD extra_roles JSON NOT NULL DEFAULT '[]'");
        $this->addSql("UPDATE auth_user SET display_name = trim(given_name || ' ' || family_name)");
        $this->addSql(<<<'SQL'
            UPDATE auth_user SET extra_roles = '["ROLE_ADMIN"]'::json WHERE administrator = TRUE
        SQL);
        $this->addSql("UPDATE auth_user SET password = '' WHERE password IS NULL");
        $this->addSql('ALTER TABLE auth_user ALTER COLUMN password SET NOT NULL');
        $this->addSql('ALTER TABLE auth_user DROP number');
        $this->addSql('ALTER TABLE auth_user DROP status');
        $this->addSql('ALTER TABLE auth_user DROP given_name');
        $this->addSql('ALTER TABLE auth_user DROP family_name');
        $this->addSql('ALTER TABLE auth_user DROP job_title');
        $this->addSql('ALTER TABLE auth_user DROP last_sign_in_at');
        $this->addSql('ALTER TABLE auth_user DROP administrator');
        $this->addSql('DROP SEQUENCE auth_user_number_seq');
    }
}
