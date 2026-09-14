<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stammdaten: Mieter, Eigentuemer und sonstige Kontakte.
 */
final class Version20260909131705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stammdaten (Party) mit Anschrift, Kontaktangaben und Referenznummer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE party (
              id UUID NOT NULL,
              reference INT NOT NULL,
              kind VARCHAR(16) NOT NULL,
              name VARCHAR(200) NOT NULL,
              given_name VARCHAR(200) DEFAULT NULL,
              sort_name VARCHAR(401) NOT NULL,
              note TEXT NOT NULL,
              roles VARCHAR(64) NOT NULL,
              address_street VARCHAR(200) DEFAULT NULL,
              address_po_box VARCHAR(60) DEFAULT NULL,
              address_postal_code VARCHAR(16) NOT NULL,
              address_city VARCHAR(120) NOT NULL,
              contact_emails JSON NOT NULL,
              contact_phones JSON NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX party_sort_name ON party (sort_name)');
        $this->addSql('CREATE UNIQUE INDEX party_reference ON party (reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE party');
    }
}
