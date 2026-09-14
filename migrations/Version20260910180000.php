<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Das Wirtschaftsjahr am Objekt.
 *
 * Tag und Monat des Beginns, nicht von–bis: ein festes Von–Bis muesste jedes
 * Jahr angefasst werden, und die Abrechnung des Vorjahres braeuchte dann den
 * ueberschriebenen Stand.
 */
final class Version20260910180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wirtschaftsjahr am Objekt';
    }

    public function up(Schema $schema): void
    {
        // Das Kalenderjahr als Vorgabe: was in den meisten Verträgen steht,
        // muss niemand eintragen.
        $this->addSql('ALTER TABLE property ADD fiscal_year_month SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE property ADD fiscal_year_day SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE property ALTER fiscal_year_month DROP DEFAULT');
        $this->addSql('ALTER TABLE property ALTER fiscal_year_day DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property DROP fiscal_year_day');
        $this->addSql('ALTER TABLE property DROP fiscal_year_month');
    }
}
