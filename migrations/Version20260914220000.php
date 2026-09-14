<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aus welchem Beschluss eine Zahlung stammt.
 *
 * Zwei Massnahmen duerfen dieselbe Einheit am selben Tag zur Kasse bitten —
 * das sind zwei Forderungen. Bisher war eine Zahlung durch Einheit, Art und
 * Faelligkeit bestimmt: der zweite Beschluss ueberschrieb den ersten, und
 * eine Berichtigung konnte die Sonderumlage einer fremden Massnahme
 * zuruecknehmen.
 *
 * Die Referenz traegt die Massnahme und **nicht die Fassung** — eine
 * Berichtigung soll dieselbe Forderung wiederfinden.
 *
 * Was aus einer Staffel kommt, traegt eine leere Referenz. Damit bleibt der
 * eindeutige Schluessel fuer Hausgeld und Nebenkosten genau der alte.
 *
 * Vorhandene Sonderumlagen bekommen keine nachtraeglich: sie liesse sich aus
 * der Zahlung nicht herleiten, und geraten waere sie eine Behauptung. Wer
 * einen alten Beschluss berichtigt, bekommt daneben eine neue Forderung und
 * muss die alte von Hand stornieren.
 */
final class Version20260914220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aus welchem Beschluss eine Zahlung stammt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE finance_advance_payment ADD reference VARCHAR(40) DEFAULT '' NOT NULL");
        $this->addSql('DROP INDEX finance_payment_due');
        $this->addSql('CREATE UNIQUE INDEX finance_payment_due ON finance_advance_payment (unit_id, kind, due_on, reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX finance_payment_due');
        $this->addSql('CREATE UNIQUE INDEX finance_payment_due ON finance_advance_payment (unit_id, kind, due_on)');
        $this->addSql('ALTER TABLE finance_advance_payment DROP reference');
    }
}
