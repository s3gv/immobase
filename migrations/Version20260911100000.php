<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Das Logo der Hausverwaltung.
 *
 * Eine Zeile, und das Bild als base64 in einer Textspalte: Doctrine gibt ein
 * BLOB als Datenstrom zurueck, und ein Datenstrom laesst sich einmal lesen.
 * Ein Drittel mehr Platz fuer eine Datei je Installation ist der ruhigere
 * Handel, und der Dump bleibt lesbar.
 */
final class Version20260911100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Das Logo der Hausverwaltung';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE settings_logo (
                id UUID NOT NULL,
                image TEXT NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE settings_logo');
    }
}
