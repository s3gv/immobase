<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Der abgerechnete Zeitraum steht am Lauf.
 *
 * Bisher wurde er jedes Mal aus dem Objekt zusammengesetzt: Tag und Monat des
 * Wirtschaftsjahresbeginns plus die Jahreszahl. Die Regel am Objekt darf sich
 * aber aendern — eine Verwaltung stellt vom Kalenderjahr auf den 1. Juli um —
 * und danach waere ein vorhandener Lauf ein Lauf ueber einen anderen
 * Zeitraum. Die Korrektur korrigierte dann nicht mehr ihr Original.
 *
 * Die vorhandenen Laeufe bekommen den Beginn, der fuer sie galt: den heutigen
 * Stand des Objekts. Etwas Besseres gibt es nicht — bis hierher war er die
 * einzige Quelle.
 */
final class Version20260911260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Der abgerechnete Zeitraum steht am Abrechnungslauf';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_statement ADD period_from DATE');

        $this->addSql(<<<'SQL'
            UPDATE billing_statement AS s
               SET period_from = make_date(s.fiscal_year, p.fiscal_year_month, p.fiscal_year_day)
              FROM property AS p
             WHERE p.id = s.property_id
            SQL);

        // Ein Lauf ohne Objekt kann es nicht geben — der Fremdschluessel
        // besteht darauf. Der Vollstaendigkeit halber trotzdem: Kalenderjahr.
        $this->addSql(<<<'SQL'
            UPDATE billing_statement
               SET period_from = make_date(fiscal_year, 1, 1)
             WHERE period_from IS NULL
            SQL);

        $this->addSql('ALTER TABLE billing_statement ALTER COLUMN period_from SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_statement DROP COLUMN period_from');
    }
}
