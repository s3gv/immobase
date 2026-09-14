<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eigentum bekommt einen Zeitraum.
 *
 * Bisher galt, wer eingetragen war. Ein Verkauf zum 1. Juli war damit nicht
 * falsch abgerechnet, sondern unsichtbar: der Kaeufer bekam die
 * Hausgeldabrechnung fuer das ganze Jahr, einschliesslich des halben, das ihm
 * nicht gehoerte.
 *
 * Beide Enden bleiben offen: die vorhandenen Eintraege sind „schon immer und
 * noch" — etwas anderes weiss die Datenbank ueber sie nicht, und ein
 * erfundenes Datum waere schlechter als keines.
 *
 * **Der eindeutige Index ueber (Einheit, Partei) faellt weg** und wird durch
 * einen Ausschluss ersetzt. Er verbot, dass dieselbe Partei zweimal an
 * derselben Einheit steht — mit einer Zeitachse ist genau das erlaubt: wer
 * verkauft und Jahre spaeter zurueckkauft, steht zweimal da, nur nicht
 * gleichzeitig.
 *
 * Der Ausschluss laeuft ueber Einheit **und Partei**, nicht ueber die Einheit
 * allein. Eine Wohnung gehoert regelmaessig mehreren gleichzeitig — einem
 * Ehepaar je zur Haelfte —, und das ist der Normalfall und kein Konflikt.
 */
final class Version20260911300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eigentum bekommt einen Zeitraum';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_unit_owner ADD owned_from DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE property_unit_owner ADD owned_to DATE DEFAULT NULL');

        $this->addSql('DROP INDEX property_unit_owner_once');

        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $this->addSql(<<<'SQL'
            ALTER TABLE property_unit_owner ADD CONSTRAINT property_unit_owner_no_overlap
                EXCLUDE USING gist (
                    unit_id WITH =,
                    party_id WITH =,
                    daterange(owned_from, owned_to, '[]') WITH &&
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_unit_owner DROP CONSTRAINT property_unit_owner_no_overlap');

        // Was sich zeitlich ueberschnitt, passt nicht mehr in den alten
        // Index: der juengste Eintrag je Einheit und Partei bleibt.
        $this->addSql(<<<'SQL'
            DELETE FROM property_unit_owner o
             WHERE EXISTS (
                   SELECT 1 FROM property_unit_owner other
                    WHERE other.unit_id = o.unit_id
                      AND other.party_id = o.party_id
                      AND (other.owned_from > o.owned_from OR o.owned_from IS NULL AND other.owned_from IS NOT NULL)
             )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX property_unit_owner_once ON property_unit_owner (unit_id, party_id)');
        $this->addSql('ALTER TABLE property_unit_owner DROP owned_from');
        $this->addSql('ALTER TABLE property_unit_owner DROP owned_to');
    }
}
