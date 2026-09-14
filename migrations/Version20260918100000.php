<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Portalzugaenge: ein Konto kann fuer eine Partei sprechen.
 *
 * Eine Spalte, und an ihr haengt die ganze Trennung. Ist sie gefuellt, ist das
 * Konto keines der Verwaltung: es bekommt `ROLE_PORTAL` statt `ROLE_USER`,
 * keine Rechte aus dem Katalog und steht in keiner Benutzerliste.
 *
 * **Kein Fremdschluessel auf `party`.** Die Anmeldung soll die Stammdaten
 * nicht kennen muessen, um jemanden anzumelden — sie fuehrt Konten, nicht
 * Menschen. Was passiert, wenn eine Partei verschwindet, entscheidet das
 * Stammdatenmodul, und zwar sichtbar im Code und nicht still in der Datenbank.
 *
 * **Eindeutig.** Ein Konto je Person und nicht je Rolle: wer Eigentuemer
 * *und* Mieter ist, meldet sich einmal an und sieht beides. Zwei Konten fuer
 * dieselbe Partei waeren zwei Postfaecher, von denen eines immer uebersehen
 * wird.
 */
final class Version20260918100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ein Konto kann für eine Partei sprechen — der Portalzugang.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_user ADD party_id UUID DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX auth_user_party ON auth_user (party_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX auth_user_party');
        $this->addSql('ALTER TABLE auth_user DROP party_id');
    }
}
