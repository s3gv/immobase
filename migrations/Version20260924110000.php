<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Umsatzsteuer in der Betriebskostenabrechnung.
 *
 * Ein Mietverhaeltnis, das nach § 9 UStG optiert hat, wird netto abgerechnet:
 * jede Zeile traegt die Steuer, die in ihr steckt, das Schreiben den Satz,
 * die Steuer auf die Kosten und die in den Vorauszahlungen (§ 14 Abs. 5
 * UStG). Alles eingefroren wie der Rest des Blattes.
 *
 * Bestehende Schreiben bekommen null und „ohne Umsatzsteuer" — so, wie sie
 * hinausgingen.
 */
final class Version20260924110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Umsatzsteuer auf Abrechnungsschreiben und -zeilen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_line ADD total_input_tax BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE billing_line ADD input_tax BIGINT DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE billing_document ADD vat_charged BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE billing_document ADD vat_rate_bps INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE billing_document ADD tax BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE billing_document ADD advances_tax BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE billing_document ADD tenancy_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_document ADD tenancy_number INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_document DROP tenancy_number');
        $this->addSql('ALTER TABLE billing_document DROP tenancy_id');
        $this->addSql('ALTER TABLE billing_document DROP advances_tax');
        $this->addSql('ALTER TABLE billing_document DROP tax');
        $this->addSql('ALTER TABLE billing_document DROP vat_rate_bps');
        $this->addSql('ALTER TABLE billing_document DROP vat_charged');
        $this->addSql('ALTER TABLE billing_line DROP input_tax');
        $this->addSql('ALTER TABLE billing_line DROP total_input_tax');
    }
}
