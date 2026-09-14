<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Erinnerungen auf dem Zeitstrahl der Uebersicht.
 *
 * **Sie gehoeren einem Menschen und keinem Datensatz.** Was wichtig ist,
 * weiss die Anwendung nicht — deshalb keine Fremdschluessel auf Objekt,
 * Einheit oder Partei, sondern eine freie Notiz. Eine Zuordnung zu erzwingen
 * hiesse, die Haelfte dessen zu verbieten, woran man erinnert werden will.
 *
 * **Kaskade am Benutzer.** Wird ein Konto geloescht, gehen seine
 * Erinnerungen mit: sie sind seine Notizen und niemandes Akte.
 *
 * Der Fremdschluessel steht hier und nicht im Mapping. Die Entity haelt die
 * Kennung als blosse Spalte, weil das Dashboard die Entity der Anmeldung
 * nicht kennen darf — die Kaskade ist damit die einzige Zusage, dass die
 * Notizen eines geloeschten Kontos wirklich verschwinden. Der Preis ist eine
 * Zeile Unterschied bei `doctrine:schema:update`, und den ist sie wert.
 */
final class Version20260921100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erinnerungen: ein Betreff, eine Notiz und ein Zeitpunkt auf dem Zeitstrahl.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dashboard_reminder (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            subject VARCHAR(120) NOT NULL,
            note VARCHAR(400) NOT NULL,
            due_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        // Gelesen wird immer dasselbe: die Erinnerungen eines Menschen in
        // einem Zeitraum. Der Index beantwortet genau diese Frage.
        $this->addSql('CREATE INDEX dashboard_reminder_owner ON dashboard_reminder (user_id, due_at)');

        $this->addSql('ALTER TABLE dashboard_reminder
            ADD CONSTRAINT dashboard_reminder_user
            FOREIGN KEY (user_id) REFERENCES auth_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dashboard_reminder');
    }
}
