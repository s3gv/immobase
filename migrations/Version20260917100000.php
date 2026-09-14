<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Das Mahnwesen.
 *
 * Vier Tabellen und eine Reihe von Zahlen.
 *
 * **Die Forderung hat kein Betragsfeld.** Was offen ist, steht in ihrer
 * Staffel — ein Tag und ein Betrag, gueltig bis zum naechsten Eintrag. Ohne
 * sie liesse sich nach der Zahlung nicht mehr belegen, worauf die Zinsen
 * liefen, und ein zweites Feld daneben waere eine zweite Wahrheit.
 *
 * **Eine Vorauszahlung wird hoechstens einmal zur Forderung.** Der eindeutige
 * Index auf Herkunft und Kennung sorgt dafuer; ohne ihn mahnte jemand
 * dieselbe Rate zweimal, und der Schuldner bekaeme zwei Briefe ueber
 * denselben Betrag. Eine eingetragene Forderung hat keine Kennung und
 * traegt darum **null** — ein leerer Text waere ein Wert wie jeder andere,
 * und dann gaebe es genau eine eingetragene Forderung auf der ganzen
 * Installation.
 *
 * **Das Schreiben traegt seine Angaben als Text.** Empfaenger, Aussteller und
 * je Forderung eine Zeile mit Betrag und Zinsen — eingefroren beim
 * Ausstellen. Ein Brief, der sich hinter dem Ruecken des Empfaengers
 * aendert, waere kein Beleg.
 *
 * **Die Basiszinssaetze bekommen nur Zeilen, wo sich etwas aendert.** Ein
 * Satz gilt, bis der naechste ihn abloest. Vorbelegt ab 2016 mit der Reihe
 * der Bundesbank — das deckt jeden Rueckstand, der die dreijaehrige
 * Verjaehrung ueberlebt hat; dreizehn gleiche Zeilen fuer die Jahre ohne
 * Bewegung waeren dreizehn Gelegenheiten, eine falsch abzutippen.
 */
final class Version20260917100000 extends AbstractMigration
{
    /**
     * Der Basiszinssatz nach § 247 BGB, in Basispunkten.
     *
     * Quelle: Deutsche Bundesbank. Von Mitte 2016 bis Ende 2022 lag er
     * unveraendert bei −0,88 % — negativ, und die Zinsrechnung haelt das aus.
     */
    private const array BASE_RATES = [
        '2016-01-01' => -83,
        '2016-07-01' => -88,
        '2023-01-01' => 162,
        '2023-07-01' => 312,
        '2024-01-01' => 362,
        '2024-07-01' => 337,
        '2025-01-01' => 227,
        '2025-07-01' => 127,
        '2026-07-01' => 152,
    ];

    public function getDescription(): string
    {
        return 'Mahnwesen: Forderungen, Staffeln, Schreiben und die Basiszinssätze';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dunning_base_rate (
            id UUID NOT NULL,
            valid_from DATE NOT NULL,
            rate_bps INT NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX dunning_base_rate_from ON dunning_base_rate (valid_from)');

        $this->addSql('CREATE TABLE dunning_claim (
            id UUID NOT NULL,
            subject VARCHAR(200) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            debtor_party_id UUID NOT NULL,
            commercial BOOLEAN NOT NULL,
            flat_fee_claimed BOOLEAN NOT NULL,
            creditor VARCHAR(16) NOT NULL,
            property_id UUID NOT NULL,
            creditor_party_ids VARCHAR(400) NOT NULL,
            unit_id UUID DEFAULT NULL,
            origin VARCHAR(16) NOT NULL,
            origin_id VARCHAR(64) DEFAULT NULL,
            due_on DATE NOT NULL,
            default_from DATE NOT NULL,
            settled_on DATE DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT dunning_claim_default CHECK (default_from >= due_on),
            CONSTRAINT dunning_claim_settled CHECK (settled_on IS NULL OR settled_on >= due_on)
        )');
        // Gebuendelt wird je Schuldner und Glaeubiger, und der Glaeubiger ist
        // Rolle, Objekt und — beim Vermieter — die Eigentuemer der Einheit.
        $this->addSql('CREATE INDEX dunning_claim_debtor
            ON dunning_claim (debtor_party_id, creditor, property_id, creditor_party_ids)');
        $this->addSql('CREATE UNIQUE INDEX dunning_claim_origin ON dunning_claim (origin, origin_id)');

        $this->addSql('CREATE TABLE dunning_claim_step (
            id UUID NOT NULL,
            claim_id UUID NOT NULL,
            starts_on DATE NOT NULL,
            open BIGINT NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX dunning_step_claim ON dunning_claim_step (claim_id)');
        $this->addSql('ALTER TABLE dunning_claim_step
            ADD CONSTRAINT dunning_step_claim_fk FOREIGN KEY (claim_id)
            REFERENCES dunning_claim (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE dunning_notice (
            id UUID NOT NULL,
            level VARCHAR(16) NOT NULL,
            pay_by DATE NOT NULL,
            note TEXT NOT NULL,
            issued_on DATE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            debtor_party_id UUID NOT NULL,
            debtor_number INT NOT NULL,
            creditor VARCHAR(16) NOT NULL,
            property_id UUID NOT NULL,
            creditor_party_ids VARCHAR(400) NOT NULL,
            number INT NOT NULL,
            costs BIGINT NOT NULL,
            flat_fee_wanted BOOLEAN NOT NULL,
            flat_fee BIGINT NOT NULL,
            creditor_name VARCHAR(400) NOT NULL,
            creditor_address VARCHAR(400) NOT NULL,
            debtor_name VARCHAR(400) NOT NULL,
            debtor_address VARCHAR(400) NOT NULL,
            payee_iban VARCHAR(34) NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT dunning_notice_edition UNIQUE (debtor_party_id, number)
        )');
        // Gesucht wird je Schuldner und Glaeubiger, und der Glaeubiger ist
        // Rolle und Objekt zusammen: die naechste Stufe und der offene
        // Entwurf haengen an genau dieser Paarung.
        $this->addSql('CREATE INDEX dunning_notice_debtor
            ON dunning_notice (debtor_party_id, creditor, property_id, creditor_party_ids)');

        $this->addSql('CREATE TABLE dunning_notice_line (
            id UUID NOT NULL,
            notice_id UUID NOT NULL,
            claim_id UUID NOT NULL,
            subject VARCHAR(200) NOT NULL,
            amount BIGINT NOT NULL,
            due_on DATE NOT NULL,
            default_from DATE NOT NULL,
            interest BIGINT NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX dunning_line_notice ON dunning_notice_line (notice_id)');
        $this->addSql('ALTER TABLE dunning_notice_line
            ADD CONSTRAINT dunning_line_notice_fk FOREIGN KEY (notice_id)
            REFERENCES dunning_notice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        foreach (self::BASE_RATES as $day => $rateBps) {
            $this->addSql(
                'INSERT INTO dunning_base_rate (id, valid_from, rate_bps) VALUES (gen_random_uuid(), ?, ?)',
                [$day, $rateBps],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dunning_claim_step DROP CONSTRAINT dunning_step_claim_fk');
        $this->addSql('ALTER TABLE dunning_notice_line DROP CONSTRAINT dunning_line_notice_fk');
        $this->addSql('DROP TABLE dunning_notice_line');
        $this->addSql('DROP TABLE dunning_notice');
        $this->addSql('DROP TABLE dunning_claim_step');
        $this->addSql('DROP TABLE dunning_claim');
        $this->addSql('DROP TABLE dunning_base_rate');
    }
}
