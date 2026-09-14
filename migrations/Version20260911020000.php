<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Hausgeld-Vorauszahlung: Betrag ab Datum, je Einheit.
 *
 * Die Nebenkosten-Vorauszahlung des Mieters steht nicht hier: sie steht im
 * Mietvertrag und damit in der Mietstaffel. Zweimal gefuehrt liefe sie
 * frueher oder spaeter auseinander.
 */
final class Version20260911020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hausgeld-Vorauszahlungen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_house_money (
                id UUID NOT NULL,
                unit_id UUID NOT NULL,
                starts_on DATE NOT NULL,
                amount BIGINT NOT NULL,
                pay_interval VARCHAR(16) NOT NULL,
                note TEXT NOT NULL,
                PRIMARY KEY(id),
                -- RESTRICT: eine Einheit mit vereinbarter Vorauszahlung
                -- verschwindet nicht nebenbei. Sie wird abgegeben.
                CONSTRAINT finance_house_money_unit FOREIGN KEY (unit_id)
                    REFERENCES property_unit (id) ON DELETE RESTRICT
            )
        SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX finance_house_money_once ON finance_house_money (unit_id, starts_on)',
        );
        $this->addSql('CREATE INDEX finance_house_money_unit ON finance_house_money (unit_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_house_money');
    }
}
