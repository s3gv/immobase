<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mehrere Anschriften je Stammdatensatz, dazu ein Status.
 *
 * Von Hand geschrieben. Der Entwurf aus doctrine:migrations:diff hielt
 * address_postal_code und status fuer eine Umbenennung — beide sind
 * varchar(16) — und haette Postleitzahlen in die Statusspalte geschrieben.
 */
final class Version20260909134807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anschriften als Liste mit Hauptanschrift, Status aktiv/archiviert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE party ADD addresses JSON');
        $this->addSql("ALTER TABLE party ADD status VARCHAR(16) NOT NULL DEFAULT 'active'");

        // Die bisherige einzelne Anschrift wird zur ersten der Liste.
        $this->addSql(<<<'SQL'
            UPDATE party SET addresses = json_build_array(
                json_build_object(
                    'kind', CASE WHEN address_po_box IS NOT NULL THEN 'poBox' ELSE 'street' END,
                    'line', COALESCE(address_po_box, address_street),
                    'postalCode', address_postal_code,
                    'city', address_city
                )
            )
        SQL);

        $this->addSql('ALTER TABLE party ALTER COLUMN addresses SET NOT NULL');
        $this->addSql('ALTER TABLE party ALTER COLUMN status DROP DEFAULT');

        $this->addSql('ALTER TABLE party DROP address_street');
        $this->addSql('ALTER TABLE party DROP address_po_box');
        $this->addSql('ALTER TABLE party DROP address_postal_code');
        $this->addSql('ALTER TABLE party DROP address_city');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE party ADD address_street VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE party ADD address_po_box VARCHAR(60) DEFAULT NULL');
        $this->addSql("ALTER TABLE party ADD address_postal_code VARCHAR(16) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE party ADD address_city VARCHAR(120) NOT NULL DEFAULT ''");

        // Nur die Hauptanschrift ueberlebt — weitere Anschriften gehen dabei
        // verloren. Das ist der Preis eines Rueckbaus auf eine einzelne.
        $this->addSql(<<<'SQL'
            UPDATE party SET
                address_street = CASE WHEN addresses->0->>'kind' = 'street' THEN addresses->0->>'line' END,
                address_po_box = CASE WHEN addresses->0->>'kind' = 'poBox' THEN addresses->0->>'line' END,
                address_postal_code = COALESCE(addresses->0->>'postalCode', ''),
                address_city = COALESCE(addresses->0->>'city', '')
        SQL);

        $this->addSql('ALTER TABLE party ALTER COLUMN address_postal_code DROP DEFAULT');
        $this->addSql('ALTER TABLE party ALTER COLUMN address_city DROP DEFAULT');
        $this->addSql('ALTER TABLE party DROP addresses');
        $this->addSql('ALTER TABLE party DROP status');
    }
}
