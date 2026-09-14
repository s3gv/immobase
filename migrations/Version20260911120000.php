<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Das Bankkonto am Objekt.
 *
 * Vier Spalten und keine eigene Tabelle: ein Konto je Objekt ist der
 * Normalfall, und eine Tabelle fuer eine Zeile waere ein Verbund fuer nichts.
 * Das zweite Konto kommt mit dem Fall, der es braucht — dann wandern die
 * Spalten, und diese Migration steht dabei im Weg wie jede andere auch.
 *
 * Leer statt NULL: „nicht angegeben" gibt es hier nur einmal, und eine
 * Abfrage muss nicht beides bedenken.
 */
final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Das Bankkonto am Objekt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE property
                ADD bank_iban VARCHAR(34) DEFAULT '' NOT NULL,
                ADD bank_bic VARCHAR(11) DEFAULT '' NOT NULL,
                ADD bank_holder VARCHAR(200) DEFAULT '' NOT NULL,
                ADD bank_label VARCHAR(120) DEFAULT '' NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE property
                DROP bank_iban, DROP bank_bic, DROP bank_holder, DROP bank_label
        SQL);
    }
}
