<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Objekte, Einheiten und Eigentum.
 *
 * Drei Tabellen und eine Sequenz. Die Objektnummer laeuft ab 20001 — eigener
 * Zahlenraum je Datenart, damit sich Konto (1001), Stammdatensatz (10001) und
 * Objekt nicht verwechseln lassen.
 *
 * Flaechen und Anteile stehen als `numeric` und nicht als Fliesskomma: beim
 * Verteilen von Kosten nach Flaeche oder Anteil sammelt Fliesskomma dieselben
 * Rundungsfehler ein wie beim Geld.
 */
final class Version20260910100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Objekte, Einheiten und Eigentum';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE property_number_seq INCREMENT BY 1 MINVALUE 20001 START 20001');

        $this->addSql(<<<'SQL'
            CREATE TABLE property (
                id UUID NOT NULL,
                number INT NOT NULL,
                name VARCHAR(200) NOT NULL,
                modes VARCHAR(32) NOT NULL,
                street VARCHAR(200) NOT NULL,
                postal_code VARCHAR(16) NOT NULL,
                city VARCHAR(120) NOT NULL,
                status VARCHAR(16) NOT NULL,
                year_built INT DEFAULT NULL,
                living_area NUMERIC(10, 2) DEFAULT NULL,
                commercial_area NUMERIC(10, 2) DEFAULT NULL,
                plot_area NUMERIC(10, 2) DEFAULT NULL,
                floors SMALLINT DEFAULT NULL,
                has_lift BOOLEAN DEFAULT NULL,
                parking_spaces SMALLINT DEFAULT NULL,
                heating_type VARCHAR(16) DEFAULT NULL,
                heating_system VARCHAR(16) DEFAULT NULL,
                hot_water VARCHAR(16) DEFAULT NULL,
                registry_court VARCHAR(120) DEFAULT NULL,
                registry_sheet VARCHAR(32) DEFAULT NULL,
                registry_district VARCHAR(120) DEFAULT NULL,
                registry_parcel VARCHAR(64) DEFAULT NULL,
                mea_denominator INT NOT NULL,
                note TEXT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX property_number ON property (number)');
        $this->addSql('CREATE INDEX property_sort_name ON property (name)');

        $this->addSql(<<<'SQL'
            CREATE TABLE property_unit (
                id UUID NOT NULL,
                property_id UUID NOT NULL,
                number INT NOT NULL,
                label VARCHAR(200) NOT NULL,
                usage VARCHAR(16) NOT NULL,
                area NUMERIC(10, 2) DEFAULT NULL,
                rooms NUMERIC(5, 2) DEFAULT NULL,
                parking_spaces SMALLINT DEFAULT NULL,
                mea_numerator NUMERIC(12, 2) NOT NULL,
                note TEXT NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT property_unit_property FOREIGN KEY (property_id)
                    REFERENCES property (id) ON DELETE CASCADE
            )
        SQL);
        // Die Nummer laeuft je Objekt. Der eindeutige Index faengt zwei
        // gleichzeitige Anlagen ab — laut statt still zweimal dieselbe.
        $this->addSql('CREATE UNIQUE INDEX property_unit_number ON property_unit (property_id, number)');

        $this->addSql(<<<'SQL'
            CREATE TABLE property_unit_owner (
                id UUID NOT NULL,
                unit_id UUID NOT NULL,
                party_id UUID NOT NULL,
                mea_numerator NUMERIC(12, 2) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT property_unit_owner_unit FOREIGN KEY (unit_id)
                    REFERENCES property_unit (id) ON DELETE CASCADE,
                -- Der Verweis auf die Stammdaten liegt in einem anderen Modul,
                -- und trotzdem gehoert er hierher. Die Anwendung schlaegt jede
                -- Kennung nach, bevor sie sie uebernimmt (AssignOwners), aber
                -- zwischen Nachschlagen und Speichern passt ein Loeschvorgang:
                -- danach zeigte die Zeile ins Leere und liesse sich nicht
                -- einmal mehr anzeigen. Das schliesst nur die Datenbank —
                -- gegen gleichzeitige Anfragen und gegen alles, was sonst noch
                -- auf sie zugreift.
                --
                -- RESTRICT und nicht CASCADE: wem etwas gehoert, dessen
                -- Stammdatensatz verschwindet nicht nebenbei. Die
                -- verstaendliche Absage steht vorher im Loeschpfad; das hier
                -- ist die letzte Grenze, nicht die erste.
                CONSTRAINT property_unit_owner_party FOREIGN KEY (party_id)
                    REFERENCES party (id) ON DELETE RESTRICT
            )
        SQL);
        // Derselbe Kontakt steht nur einmal an derselben Einheit.
        $this->addSql('CREATE UNIQUE INDEX property_unit_owner_once ON property_unit_owner (unit_id, party_id)');
        // Gefragt wird auch andersherum: haelt dieser Kontakt Eigentum? Daran
        // haengt die Loeschsperre der Stammdaten.
        $this->addSql('CREATE INDEX property_unit_owner_party ON property_unit_owner (party_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE property_unit_owner');
        $this->addSql('DROP TABLE property_unit');
        $this->addSql('DROP TABLE property');
        $this->addSql('DROP SEQUENCE property_number_seq');
    }
}
