<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wofuer eine Kostenposition da ist.
 *
 * Zwei Spalten: die Nummer der beschlossenen Massnahme und ihr Name. Damit
 * laesst sich die Frage beantworten, die bisher niemand beantworten konnte —
 * **stehen den Sonderumlagen eines Jahres auch die Kosten gegenueber, fuer
 * die sie beschlossen wurden.**
 *
 * Die Nummer ist dieselbe, die an der Sonderumlage steht: ohne Einheit, ohne
 * Fassung. Der Name steht eingefroren daneben, damit die Finanzen ihn zeigen
 * koennen, ohne bei jeder Zeile in Billing nachzufragen.
 *
 * Leer heisst: gehoert zu keinem Beschluss. Das ist der Normalfall — die
 * Grundsteuer gehoert zu keiner Massnahme.
 */
final class Version20260915100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wofür eine Kostenposition da ist';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE finance_cost_item
                ADD measure_reference VARCHAR(40) DEFAULT '' NOT NULL,
                ADD measure_label VARCHAR(200) DEFAULT '' NOT NULL
            SQL);
        $this->addSql('CREATE INDEX finance_cost_item_measure ON finance_cost_item (measure_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX finance_cost_item_measure');
        $this->addSql('ALTER TABLE finance_cost_item DROP measure_reference, DROP measure_label');
    }
}
