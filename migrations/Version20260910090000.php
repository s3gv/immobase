<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rollen und Rechte statt der Spalte "administrator".
 *
 * Vier Tabellen: die Rollen, ihre Rechte, die Zuordnung zu Konten und die
 * Zusatzrechte einzelner Konten. Der Katalog der Rechte steht nicht darunter —
 * er steht im Code, und genau deshalb kann er nicht von der Datenbank
 * abweichen.
 *
 * Die vorhandenen Konten verlieren nichts: wer Administrator war, bekommt die
 * Systemrolle. Gibt es Konten ohne dieses Kennzeichen, entsteht zusaetzlich
 * die rechtefreie Rolle "Mitarbeiter" — sonst stuende die Zusicherung „jedes
 * Konto hat eine Rolle" vom ersten Tag an auf dem Papier. Der Administrator
 * fuellt sie danach ueber die Matrix.
 */
final class Version20260910090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rollen, Rechte und Zuordnungen; die Spalte administrator entfällt';
    }

    public function up(Schema $schema): void
    {
        $this->createTables();
        $this->carryOverExistingAccounts();

        $this->addSql('ALTER TABLE auth_user DROP COLUMN administrator');
    }

    /**
     * Zurueck geht es, solange die Systemrolle noch da ist.
     *
     * Rechte, die inzwischen an anderen Rollen haengen, gehen dabei verloren —
     * die alte Spalte kann nur "ja" oder "nein". Das steht hier, damit es
     * niemanden ueberrascht.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_user ADD administrator BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE auth_user SET administrator = true WHERE id IN (
                SELECT ur.user_id FROM auth_user_role ur
                JOIN auth_role r ON r.id = ur.role_id
                WHERE r.is_system = true
            )
        SQL);

        $this->addSql('DROP TABLE auth_user_permission');
        $this->addSql('DROP TABLE auth_user_role');
        $this->addSql('DROP TABLE auth_role_permission');
        $this->addSql('DROP TABLE auth_role');
    }

    private function createTables(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE auth_role (
                id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                is_system BOOLEAN NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX auth_role_name ON auth_role (name)');

        // Der Schluessel ist eine Zeichenkette und kein Fremdschluessel: den
        // Katalog haelt der Code. Zeilen zu Schluesseln, die es nicht mehr
        // gibt, gewaehren nichts und raeumt immobase:permission:prune weg.
        $this->addSql(<<<'SQL'
            CREATE TABLE auth_role_permission (
                role_id UUID NOT NULL,
                permission_key VARCHAR(64) NOT NULL,
                PRIMARY KEY(role_id, permission_key),
                CONSTRAINT auth_role_permission_role FOREIGN KEY (role_id)
                    REFERENCES auth_role (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE auth_user_role (
                user_id UUID NOT NULL,
                role_id UUID NOT NULL,
                PRIMARY KEY(user_id, role_id),
                CONSTRAINT auth_user_role_user FOREIGN KEY (user_id)
                    REFERENCES auth_user (id) ON DELETE CASCADE,
                CONSTRAINT auth_user_role_role FOREIGN KEY (role_id)
                    REFERENCES auth_role (id) ON DELETE CASCADE
            )
        SQL);
        // Gefragt wird in beide Richtungen: welche Rollen hat ein Konto, und
        // wie viele Konten haengen an einer Rolle. Der Primaerschluessel deckt
        // nur die erste ab.
        $this->addSql('CREATE INDEX auth_user_role_by_role ON auth_user_role (role_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE auth_user_permission (
                user_id UUID NOT NULL,
                permission_key VARCHAR(64) NOT NULL,
                PRIMARY KEY(user_id, permission_key),
                CONSTRAINT auth_user_permission_user FOREIGN KEY (user_id)
                    REFERENCES auth_user (id) ON DELETE CASCADE
            )
        SQL);
    }

    private function carryOverExistingAccounts(): void
    {
        $this->addSql("INSERT INTO auth_role (id, name, is_system) VALUES (gen_random_uuid(), 'Administrator', true)");
        $this->addSql(<<<'SQL'
            INSERT INTO auth_user_role (user_id, role_id)
            SELECT u.id, r.id FROM auth_user u, auth_role r
            WHERE r.is_system = true AND u.administrator = true
        SQL);

        // Nur wenn es sie braucht: eine leere Rolle in einer Installation, in
        // der alle Administrator sind, waere Inventar ohne Zweck.
        $this->addSql(<<<'SQL'
            INSERT INTO auth_role (id, name, is_system)
            SELECT gen_random_uuid(), 'Mitarbeiter', false
            WHERE EXISTS (SELECT 1 FROM auth_user WHERE administrator = false)
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO auth_user_role (user_id, role_id)
            SELECT u.id, r.id FROM auth_user u, auth_role r
            WHERE r.name = 'Mitarbeiter' AND u.administrator = false
        SQL);
    }
}
