<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Abwicklung: Objekte und Einheiten koennen enden.
 *
 * Ein Verwaltervertrag laeuft aus, eine Einheit wird verkauft. Bisher gab es
 * dafuer nur Loeschen — und damit waere die Abrechnung des laufenden Jahres
 * mit weg.
 */
final class Version20260910160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Objekte und Einheiten mit Stichtag beenden';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property ADD closed_on DATE DEFAULT NULL');
        $this->addSql("ALTER TABLE property ADD closed_note TEXT DEFAULT '' NOT NULL");

        // Einheiten hatten bisher keinen Zustand: sie waren einfach da.
        $this->addSql("ALTER TABLE property_unit ADD status VARCHAR(16) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE property_unit ADD closed_on DATE DEFAULT NULL');
        $this->addSql("ALTER TABLE property_unit ADD closed_note TEXT DEFAULT '' NOT NULL");

        // Die Vorgabe hat ihren Dienst getan; ab jetzt setzt sie die Anwendung.
        $this->addSql('ALTER TABLE property_unit ALTER status DROP DEFAULT');

        $this->addSql('CREATE INDEX property_open ON property (closed_on)');
        $this->addSql('CREATE INDEX property_unit_open ON property_unit (closed_on)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX property_unit_open');
        $this->addSql('DROP INDEX property_open');
        $this->addSql('ALTER TABLE property_unit DROP closed_note');
        $this->addSql('ALTER TABLE property_unit DROP closed_on');
        $this->addSql('ALTER TABLE property_unit DROP status');
        $this->addSql('ALTER TABLE property DROP closed_note');
        $this->addSql('ALTER TABLE property DROP closed_on');
        $this->addSql("UPDATE property SET status = 'active' WHERE status = 'ended'");
    }
}
