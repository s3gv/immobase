<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eine Hausgeldstufe sagt, woher sie kommt.
 *
 * Bisher stand nur ein Betrag da. Sobald der Wirtschaftsplan die Staffel
 * schreibt, ist das zu wenig: ein beschlossener Vorschuss und eine von Hand
 * eingetragene Zahl sehen sonst gleich aus, und die erste Frage bei einem
 * bestrittenen Hausgeld ist, wer das beschlossen hat.
 *
 * Die vorhandenen Stufen sind von Hand angelegt — es gab bisher nichts
 * anderes. Sie bekommen darum keine Referenz und kein Kennzeichen.
 */
final class Version20260912100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eine Hausgeldstufe sagt, woher sie kommt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_house_money ADD plan_reference VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE finance_house_money ADD changed_by_hand BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_house_money DROP plan_reference');
        $this->addSql('ALTER TABLE finance_house_money DROP changed_by_hand');
    }
}
