<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Personenzahl einer Einheit ohne Mietvertrag.
 *
 * Sie hing bisher ausschliesslich am Mietverhaeltnis. Eine selbstbewohnte
 * Eigentumswohnung hat keins, eine leerstehende auch nicht — und jeder
 * Verteilerschluessel nach Personen lief fuer sie ins Leere.
 *
 * Eine Staffel und kein Feld: abgerechnet wird ein Jahr, das vorbei ist. Wer
 * die Zahl ueberschreibt, weil ein Kind geboren wurde, veraendert still die
 * Abrechnung des Vorjahres.
 */
final class Version20260911320000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personenzahl an der Einheit, für die Tage ohne Mietvertrag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE property_unit_household (
                id UUID NOT NULL,
                unit_id UUID NOT NULL,
                starts_on DATE NOT NULL,
                people SMALLINT NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE INDEX property_unit_household_unit ON property_unit_household (unit_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX property_unit_household_once
                ON property_unit_household (unit_id, starts_on)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE property_unit_household
                ADD CONSTRAINT property_unit_household_unit_fk
                FOREIGN KEY (unit_id) REFERENCES property_unit (id)
                ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE property_unit_household');
    }
}
