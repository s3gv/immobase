<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Erhaltungsruecklage — als Bewegungen, nicht als Konto.
 *
 * Das Konto ist die Summe seiner Bewegungen. Eine Zeile, die nur sagt
 * „dieses Objekt hat ein Konto", waere bei jedem neuen Objekt ein leerer
 * Datensatz.
 */
final class Version20260911040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bewegungen der Erhaltungsrücklage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_reserve_movement (
                id UUID NOT NULL,
                property_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                occurred_on DATE NOT NULL,
                amount BIGINT NOT NULL,
                unit_id UUID DEFAULT NULL,
                note TEXT NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT finance_reserve_property FOREIGN KEY (property_id)
                    REFERENCES property (id) ON DELETE RESTRICT,
                -- Nur bei Zuführung und Sonderumlage gesetzt: wer eingezahlt
                -- hat, muss nachvollziehbar bleiben.
                CONSTRAINT finance_reserve_unit FOREIGN KEY (unit_id)
                    REFERENCES property_unit (id) ON DELETE RESTRICT
            )
        SQL);
        $this->addSql('CREATE INDEX finance_reserve_property ON finance_reserve_movement (property_id)');

        // Einen Anfangsbestand gibt es höchstens einmal je Objekt. Die
        // Anwendung sagt es verständlich, der Teilindex hält es fest.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX finance_reserve_one_opening ON finance_reserve_movement (property_id)
                WHERE kind = 'opening'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_reserve_movement');
    }
}
