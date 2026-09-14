<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Was auf einer Korrektur schon abgerechnet war.
 *
 * Nur bei Korrekturen gesetzt. Die Zeilen zeigen den heutigen, richtigen
 * Stand; unten steht die Differenz. Ohne diese Spalte stuende dieselbe
 * Forderung zweimal in der Welt.
 */
final class Version20260911220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Was auf einer Korrektur schon abgerechnet war';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_document ADD settled_balance BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_document DROP settled_balance');
    }
}
