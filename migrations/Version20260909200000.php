<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Einstellungen als Schluessel und Wert.
 *
 * Eine Zeile je Option statt einer Spalte je Option: kuenftige Einstellungen
 * brauchen dann keine Migration. Was ein Wert bedeutet, weiss die Stelle, die
 * ihn liest.
 */
final class Version20260909200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabelle settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE settings (
                name VARCHAR(100) NOT NULL,
                value VARCHAR(500) NOT NULL,
                PRIMARY KEY(name)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE settings');
    }
}
