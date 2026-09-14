<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Beenden statt loeschen — bei Kostenpositionen und Ruecklagenbewegungen.
 *
 * Was einmal gelaufen ist, wird nicht geloescht: es kann Grundlage einer
 * Abrechnung sein, und abgerechnet wird das vergangene Jahr, manchmal das
 * vorletzte.
 *
 * Der Teilindex fuer den Anfangsbestand nimmt das Storno mit auf: ein
 * stornierter Anfangsbestand darf durch den richtigen ersetzt werden, sonst
 * liesse sich eine Fehleingabe zwar zuruecknehmen, aber nie berichtigen.
 */
final class Version20260911080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kostenpositionen beenden, Rücklagenbewegungen stornieren';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_cost_item ADD ends_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE finance_reserve_movement ADD reversed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('DROP INDEX finance_reserve_one_opening');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX finance_reserve_one_opening ON finance_reserve_movement (property_id)
                WHERE kind = 'opening' AND reversed_at IS NULL
        SQL);
    }

    /**
     * Es gibt keinen Weg zurueck.
     *
     * Der vorgesehene Ablauf ist „Anfangsbestand stornieren, richtigen
     * buchen". Danach stehen zwei `opening`-Zeilen da, eine davon storniert
     * — der alte Index kannte diesen Unterschied nicht und liesse sich gar
     * nicht mehr anlegen. Der Rueckbau braeche also mitten drin mit einem
     * Datenbankfehler ab, nachdem er `reversed_at` schon entfernt hat: die
     * Storno-Information waere fort und der Index trotzdem nicht da.
     *
     * Ein Abbruch vorher ist ehrlicher als ein halber Rueckbau.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Ein stornierter Anfangsbestand lässt sich im alten Schema nicht darstellen. '
            .'Zurück geht es nur über eine Sicherung.',
        );
    }
}
