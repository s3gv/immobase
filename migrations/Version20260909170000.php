<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eine Sequenz fuer die Referenznummern.
 *
 * Bisher wurde die naechste Nummer als MAX(reference) + 1 gelesen und der
 * Datensatz erst danach gespeichert. Zwei gleichzeitig abgeschickte Abläufe
 * lasen dieselbe Nummer; der zweite lief in den eindeutigen Index und endete
 * mit einem Serverfehler. nextval() vergibt dagegen jede Nummer nur einmal,
 * ohne dass jemand warten muss.
 *
 * Von Hand geschrieben: Doctrine kennt die Sequenz nicht, sie gehoert zu
 * keiner Id. Der Namenszusatz muss sein — der eindeutige Index heisst
 * party_reference, und in PostgreSQL teilen Index und Sequenz einen
 * Namensraum.
 */
final class Version20260909170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Referenznummern aus einer Sequenz statt aus MAX(reference) + 1';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE party_reference_seq INCREMENT BY 1 MINVALUE 10001 START WITH 10001');

        // Vorhandene Nummern ueberspringen. false heisst: der naechste
        // nextval()-Aufruf liefert genau diesen Wert, nicht den danach.
        $this->addSql(<<<'SQL'
            SELECT setval(
                'party_reference_seq',
                GREATEST(10001, (SELECT COALESCE(MAX(reference), 0) + 1 FROM party)),
                false
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE party_reference_seq');
    }
}
