<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Der Ruecklagenauszug an der Jahresabrechnung.
 *
 * Sechs Spalten, dieselben wie am Vermoegensbericht: Anfangsbestand,
 * Zufuehrungen, Sonderumlagen, Zinsen, Entnahmen, Schlussbestand. Sie stehen
 * an der Abrechnung und nicht an ihren Schreiben — die Ruecklage gehoert der
 * Gemeinschaft, und fuer alle Empfaenger steht dasselbe darin.
 *
 * Vorhandene Abrechnungen bekommen Nullen. Ihr Auszug nachtraeglich zu
 * rechnen hiesse, ihn auf ein Blatt zu schreiben, das jemand schon hat.
 */
final class Version20260914200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Der Rücklagenauszug an der Jahresabrechnung';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_statement
                ADD reserve_opening BIGINT DEFAULT 0 NOT NULL,
                ADD reserve_contributions BIGINT DEFAULT 0 NOT NULL,
                ADD reserve_levies BIGINT DEFAULT 0 NOT NULL,
                ADD reserve_interest BIGINT DEFAULT 0 NOT NULL,
                ADD reserve_withdrawals BIGINT DEFAULT 0 NOT NULL,
                ADD reserve_closing BIGINT DEFAULT 0 NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_statement
                DROP reserve_opening,
                DROP reserve_contributions,
                DROP reserve_levies,
                DROP reserve_interest,
                DROP reserve_withdrawals,
                DROP reserve_closing
            SQL);
    }
}
