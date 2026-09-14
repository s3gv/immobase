<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Was eine Dauermietrechnung an fremden Stellen braucht.
 *
 * **Die Steuernummer gehoert dem Eigentuemer.** § 14 Abs. 4 Nr. 2 UStG
 * verlangt die Nummer des leistenden Unternehmers, und das ist der Vermieter
 * — nicht die Verwaltung, die das Schreiben aufsetzt. Ein Feld fuer
 * Steuernummer und USt-IdNr., weil das Gesetz beides zulaesst.
 *
 * **Die Umsatzsteueroption gehoert dem Mietverhaeltnis.** Nicht der Einheit:
 * dieselbe Gewerbeeinheit kann nacheinander an einen optierenden und einen
 * nicht optierenden Mieter gehen. Die Option nach § 9 UStG haengt daran, was
 * der Mieter mit dem Objekt macht.
 *
 * Der Satz steht in Basispunkten — 1900 fuer 19 %, dieselbe Schreibweise wie
 * beim Darlehen. Ein Prozentsatz als Dezimalzahl waere genau die Zahl, die
 * man nicht rechnen kann.
 */
final class Version20260916100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Steuernummer am Eigentümer, Umsatzsteueroption am Mietverhältnis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE party ADD tax_number VARCHAR(40) DEFAULT '' NOT NULL");

        $this->addSql('ALTER TABLE tenancy ADD vat_charged BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE tenancy ADD vat_rate_bps INT DEFAULT 0 NOT NULL');

        // Ausgewiesen heisst: mit einem Satz. Null Prozent waere keine Option,
        // sondern ein Steuerausweis ueber nichts — und der schuldet nach
        // § 14c UStG trotzdem, was draufsteht.
        $this->addSql(<<<'SQL'
            ALTER TABLE tenancy ADD CONSTRAINT tenancy_vat_needs_a_rate
                CHECK (vat_charged = false OR vat_rate_bps > 0)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenancy DROP CONSTRAINT tenancy_vat_needs_a_rate');
        $this->addSql('ALTER TABLE tenancy DROP vat_rate_bps');
        $this->addSql('ALTER TABLE tenancy DROP vat_charged');
        $this->addSql('ALTER TABLE party DROP tax_number');
    }
}
