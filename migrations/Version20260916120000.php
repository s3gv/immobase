<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Dauermietrechnungen.
 *
 * Ein Schreiben je Mietvertrag und Fassung. Anders als die uebrigen
 * Schreiben des Moduls haengt es nicht an einem Objekt und einem Jahr — es
 * gilt ab einem Tag und bis sich ein Bestandteil aendert.
 *
 * **Das Ende ist leer, solange die Fassung die letzte ist.** Geschlossen
 * wird es von der Folgefassung; damit ist die Kette lueckenlos und jeder Tag
 * gehoert genau einer Fassung.
 *
 * Der Inhalt steht als Text und Zahl daneben und nicht als Verweis: wer
 * umzieht oder seine Steuernummer aendert, aendert damit keine zugestellte
 * Rechnung. Genau diese Spalten sind spaeter die Quelle einer XRechnung.
 */
final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die Dauermietrechnungen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_rent_invoice (
                id UUID NOT NULL,
                number INT NOT NULL,
                iteration SMALLINT NOT NULL,
                corrects_id UUID DEFAULT NULL,
                tenancy_id UUID NOT NULL,
                tenancy_number INT NOT NULL,
                property_id UUID NOT NULL,
                valid_from DATE NOT NULL,
                valid_until DATE DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                released_on DATE DEFAULT NULL,
                landlord_name VARCHAR(400) NOT NULL,
                landlord_address VARCHAR(400) NOT NULL,
                landlord_tax_number VARCHAR(40) NOT NULL,
                tenant_name VARCHAR(400) NOT NULL,
                tenant_address VARCHAR(400) NOT NULL,
                let_label VARCHAR(400) NOT NULL,
                rent_base BIGINT NOT NULL,
                rent_operating BIGINT NOT NULL,
                rent_heating BIGINT NOT NULL,
                rent_parking BIGINT NOT NULL,
                vat_charged BOOLEAN NOT NULL,
                vat_rate_bps INT NOT NULL,
                payee_name VARCHAR(200) NOT NULL,
                payee_iban VARCHAR(34) NOT NULL,
                label VARCHAR(200) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                -- Das Mietverhaeltnis haelt seine Rechnungen fest: was einmal
                -- ausgestellt ist, ist ein Beleg, und Belege verschwinden
                -- nicht, weil jemand den Vertrag loescht.
                CONSTRAINT billing_rent_invoice_tenancy_fk FOREIGN KEY (tenancy_id)
                    REFERENCES tenancy (id) ON DELETE RESTRICT
            )
        SQL);

        $this->addSql('CREATE INDEX billing_rent_invoice_tenancy ON billing_rent_invoice (tenancy_id)');
        $this->addSql('CREATE INDEX billing_rent_invoice_property ON billing_rent_invoice (property_id)');

        // Je Mietverhaeltnis eine Fassung mit dieser Nummer und dieser
        // Iteration — zwei Schreiben mit derselben Nummer waeren fuer den
        // Vorsteuerabzug des Mieters ein Problem, und die Nummer steht drauf.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX billing_rent_invoice_edition
                ON billing_rent_invoice (tenancy_id, number, iteration)
        SQL);

        // Ein Ende vor dem Beginn ist kein Zeitraum.
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_rent_invoice ADD CONSTRAINT billing_rent_invoice_period
                CHECK (valid_until IS NULL OR valid_until >= valid_from)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_rent_invoice');
    }
}
