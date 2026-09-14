<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wofuer die Vorauszahlung war.
 *
 * Auf einem Schreiben stehen seit der Sonderumlage zwei Sorten Zahlung
 * nebeneinander, und am selben Tag: das Hausgeld und die Rate der
 * Sonderumlage. Ohne die Art steht dort zweimal derselbe Tag mit zwei
 * Betraegen, und der Empfaenger fragt nach.
 *
 * Die vorhandenen Zeilen wissen es: eine Zahlung auf einer Hausgeldabrechnung
 * war Hausgeld, eine auf einer Nebenkostenabrechnung eine
 * Betriebskostenvorauszahlung — etwas anderes konnte bis heute nicht
 * daraufstehen. Darum wird die Art aus dem Schreiben nachgetragen und nicht
 * geraten.
 */
final class Version20260914160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wofür die Vorauszahlung war';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE billing_advance ADD kind VARCHAR(16) DEFAULT '' NOT NULL");
        $this->addSql(<<<'SQL'
            UPDATE billing_advance a
            SET kind = d.kind
            FROM billing_document d
            WHERE d.id = a.document_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_advance DROP kind');
    }
}
