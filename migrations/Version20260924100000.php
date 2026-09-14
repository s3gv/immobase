<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Umsatzsteuer, die in einem Kostenjahr steckt.
 *
 * Wer nach § 9 UStG optiert, zieht sie als Vorsteuer ab und legt an seine
 * optierten Mieter netto um — darauf kommt dann deren Steuersatz. Ohne diese
 * Zahl zahlte der Mieter Steuer auf die Steuer.
 *
 * Null ist der Regelfall und heisst: keine ausgewiesen, oder kein Abzug.
 * Grundsteuer und Versicherung tragen keine.
 */
final class Version20260924100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enthaltene Umsatzsteuer je Kostenjahr.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_cost_item_year ADD input_tax BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE finance_cost_item_year ADD CONSTRAINT finance_cost_item_year_input_tax CHECK (input_tax >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_cost_item_year DROP CONSTRAINT finance_cost_item_year_input_tax');
        $this->addSql('ALTER TABLE finance_cost_item_year DROP input_tax');
    }
}
