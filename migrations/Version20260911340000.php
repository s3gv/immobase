<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Der Ausschluss wird erst beim Abschluss geprueft.
 *
 * Ein Verkauf mit Rueckkauf sind zwei Aenderungen an derselben Einheit: die
 * vorhandene Zeile bekommt ein Ende, und eine neue faengt spaeter an. In
 * welcher Reihenfolge Doctrine beides schreibt, entscheidet es selbst — und
 * es schreibt die neue Zeile zuerst. In diesem Augenblick reicht die alte
 * noch bis in alle Zukunft, die Zeitraeume ueberschneiden sich, und die
 * Datenbank lehnt ab. Nach beiden Anweisungen stimmt es wieder.
 *
 * Genau dafuer gibt es aufschiebbare Bedingungen: geprueft wird am Ende der
 * Transaktion, wenn alles geschrieben ist. Die Zusicherung bleibt dieselbe —
 * niemand kann zwei sich ueberschneidende Zeitraeume hinterlassen —, nur der
 * Zeitpunkt der Pruefung ist ein anderer.
 *
 * Die Alternative waere gewesen, in der Anwendung zweimal zu speichern: erst
 * die Aenderungen, dann die Neuen. Das haelt genau so lange, bis jemand eine
 * dritte Reihenfolge braucht.
 */
final class Version20260911340000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Der Eigentumsausschluss wird erst beim Abschluss geprüft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_unit_owner DROP CONSTRAINT property_unit_owner_no_overlap');
        $this->addSql(<<<'SQL'
            ALTER TABLE property_unit_owner ADD CONSTRAINT property_unit_owner_no_overlap
                EXCLUDE USING gist (
                    unit_id WITH =,
                    party_id WITH =,
                    daterange(owned_from, owned_to, '[]') WITH &&
                ) DEFERRABLE INITIALLY DEFERRED
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_unit_owner DROP CONSTRAINT property_unit_owner_no_overlap');
        $this->addSql(<<<'SQL'
            ALTER TABLE property_unit_owner ADD CONSTRAINT property_unit_owner_no_overlap
                EXCLUDE USING gist (
                    unit_id WITH =,
                    party_id WITH =,
                    daterange(owned_from, owned_to, '[]') WITH &&
                )
            SQL);
    }
}
