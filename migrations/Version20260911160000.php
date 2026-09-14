<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die geleisteten Vorauszahlungen.
 *
 * Eine Zeile je Faelligkeit, fuer Hausgeld und Nebenkosten gemeinsam: die
 * Frage „ist das Geld gekommen" ist beide Male dieselbe. Das ist keine
 * Buchhaltung — kein Zahlungsdatum, keine Zuordnung zu einem Kontoauszug.
 * Die Abrechnung braucht eine Zahl, und die steht hier.
 *
 * `settled` ist wahr, weil der Normalfall die Zahlung ist. Wer eine vermisst,
 * schaltet um und traegt bei Bedarf einen Teilbetrag ein; `paid` bleibt sonst
 * leer, und leer heisst „nichts gekommen".
 */
final class Version20260911160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Die geleisteten Vorauszahlungen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_advance_payment (
                id UUID NOT NULL,
                unit_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                fiscal_year SMALLINT NOT NULL,
                due_on DATE NOT NULL,
                expected BIGINT NOT NULL,
                settled BOOLEAN DEFAULT true NOT NULL,
                paid BIGINT DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX finance_payment_due
                ON finance_advance_payment (unit_id, kind, due_on)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX finance_payment_year
                ON finance_advance_payment (unit_id, fiscal_year)
        SQL);

        // Verschwindet die Einheit, verschwinden ihre Zahlungen. Sie sagen
        // ohne sie nichts aus, und eine Einheit laesst sich ohnehin nur
        // loeschen, solange sie nie verwaltet wurde.
        $this->addSql(<<<'SQL'
            ALTER TABLE finance_advance_payment
                ADD CONSTRAINT finance_payment_unit
                FOREIGN KEY (unit_id) REFERENCES property_unit (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_advance_payment');
    }
}
