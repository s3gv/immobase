<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drei Zustaende, ein Ueberschneidungsschutz und die Personenzahl als Staffel.
 *
 * „Inaktiv" musste bisher fuer zweierlei herhalten: ein frisch angelegtes
 * Mietverhaeltnis war inaktiv, ein beendetes auch. Das eine ist angefangene
 * Arbeit, das andere Geschichte — und solange beide denselben Zustand tragen,
 * ist entweder der Entwurf unloeschbar oder die Geschichte loeschbar.
 */
final class Version20260910140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mietverhältnisse: Entwurf/Aktiv/Beendet, Überschneidungsschutz, Haushaltsstaffel';
    }

    public function up(Schema $schema): void
    {
        // Was ein Ende traegt, war beendet; alles andere ist ein Entwurf.
        $this->addSql("UPDATE tenancy SET status = 'ended' WHERE status = 'inactive' AND ends_on IS NOT NULL");
        $this->addSql("UPDATE tenancy SET status = 'draft' WHERE status = 'inactive'");

        $this->addSql(<<<'SQL'
            CREATE TABLE tenancy_household (
                id UUID NOT NULL,
                tenancy_id UUID NOT NULL,
                starts_on DATE NOT NULL,
                people SMALLINT NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT tenancy_household_tenancy FOREIGN KEY (tenancy_id)
                    REFERENCES tenancy (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX tenancy_household_once ON tenancy_household (tenancy_id, starts_on)');

        // Der bisherige Einzelwert wird zur ersten Stufe. Ohne Mietbeginn
        // gaebe es keinen Tag, ab dem er gilt — dann faellt er weg, denn eine
        // Personenzahl ohne Zeitpunkt ist fuer die Abrechnung wertlos.
        $this->addSql(<<<'SQL'
            INSERT INTO tenancy_household (id, tenancy_id, starts_on, people)
            SELECT gen_random_uuid(), id, starts_on, household_size
            FROM tenancy
            WHERE household_size IS NOT NULL AND starts_on IS NOT NULL
        SQL);
        $this->addSql('ALTER TABLE tenancy DROP household_size');

        // Zwei beendete Mietverhaeltnisse mit ueberlappender Laufzeit waren
        // bisher moeglich — tagesgenau gerechnet zahlten dann zwei Parteien
        // denselben Tag. Der eindeutige Teilindex schuetzt nur die Gegenwart.
        //
        // Entwuerfe bleiben ausgenommen, und das ist wesentlich: ein
        // laufendes Mietverhaeltnis ist meist unbefristet und reicht damit
        // bis in alle Zukunft. Waeren sie dabei, liesse sich nie ein
        // Nachmieter vorab erfassen.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $this->addSql(<<<'SQL'
            ALTER TABLE tenancy ADD CONSTRAINT tenancy_no_overlap
                EXCLUDE USING gist (
                    unit_id WITH =,
                    daterange(starts_on, ends_on, '[]') WITH &&
                ) WHERE (status <> 'draft' AND starts_on IS NOT NULL)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenancy DROP CONSTRAINT tenancy_no_overlap');
        $this->addSql('ALTER TABLE tenancy ADD household_size SMALLINT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE tenancy t SET household_size = (
                SELECT h.people FROM tenancy_household h
                WHERE h.tenancy_id = t.id
                ORDER BY h.starts_on DESC LIMIT 1
            )
        SQL);
        $this->addSql('DROP TABLE tenancy_household');
        $this->addSql("UPDATE tenancy SET status = 'inactive' WHERE status IN ('draft', 'ended')");
    }
}
