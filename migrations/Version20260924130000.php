<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Was eine E-Rechnung braucht, eingefroren an beiden Belegen.
 *
 * An der Dauermietrechnung und am Abrechnungsschreiben dieselben Spalten:
 * Anschriften in Teilen, Referenz und Adresse des Mieters, Ansprechpartner
 * der Verwaltung, Zahlungsabrede. Das Abrechnungsschreiben bekommt dazu, wer
 * die Rechnung stellt und wohin gezahlt wird — auf der Dauermietrechnung steht
 * das schon.
 *
 * Bestehende Belege bekommen `einvoice_captured = false`: sie gingen ohne
 * diese Angaben hinaus, und eine E-Rechnung gibt es fuer sie erst mit einer
 * neuen Fassung.
 */
final class Version20260924130000 extends AbstractMigration
{
    private const array EINVOICE = [
        "einvoice_captured BOOLEAN DEFAULT false NOT NULL",
        "seller_street VARCHAR(200) DEFAULT '' NOT NULL",
        "seller_postal_code VARCHAR(20) DEFAULT '' NOT NULL",
        "seller_city VARCHAR(100) DEFAULT '' NOT NULL",
        "buyer_street VARCHAR(200) DEFAULT '' NOT NULL",
        "buyer_postal_code VARCHAR(20) DEFAULT '' NOT NULL",
        "buyer_city VARCHAR(100) DEFAULT '' NOT NULL",
        "buyer_reference VARCHAR(100) DEFAULT '' NOT NULL",
        "buyer_e_address VARCHAR(200) DEFAULT '' NOT NULL",
        "contact_name VARCHAR(200) DEFAULT '' NOT NULL",
        "contact_phone VARCHAR(60) DEFAULT '' NOT NULL",
        "contact_email VARCHAR(200) DEFAULT '' NOT NULL",
        "payment_method VARCHAR(16) DEFAULT 'transfer' NOT NULL",
        "payment_due VARCHAR(24) DEFAULT '' NOT NULL",
        "sepa_mandate VARCHAR(35) DEFAULT '' NOT NULL",
        "debtor_iban VARCHAR(34) DEFAULT '' NOT NULL",
        "creditor_id VARCHAR(35) DEFAULT '' NOT NULL",
    ];

    private const array SELLER = [
        "landlord_name VARCHAR(400) DEFAULT '' NOT NULL",
        "landlord_address TEXT DEFAULT '' NOT NULL",
        "landlord_tax_number VARCHAR(40) DEFAULT '' NOT NULL",
        "payee_name VARCHAR(200) DEFAULT '' NOT NULL",
        "payee_iban VARCHAR(34) DEFAULT '' NOT NULL",
    ];

    public function getDescription(): string
    {
        return 'E-Rechnungs-Angaben an Dauermietrechnung und Abrechnungsschreiben.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::EINVOICE as $column) {
            $this->addSql('ALTER TABLE billing_rent_invoice ADD '.$column);
            $this->addSql('ALTER TABLE billing_document ADD '.$column);
        }

        foreach (self::SELLER as $column) {
            $this->addSql('ALTER TABLE billing_document ADD '.$column);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::SELLER as $column) {
            $this->addSql('ALTER TABLE billing_document DROP '.strtok($column, ' '));
        }

        foreach (self::EINVOICE as $column) {
            $this->addSql('ALTER TABLE billing_document DROP '.strtok($column, ' '));
            $this->addSql('ALTER TABLE billing_rent_invoice DROP '.strtok($column, ' '));
        }
    }
}
