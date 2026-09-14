<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * „Nach Verbrauch" wird ein Systemschluessel — und Mengen bekommen ihre Einheit.
 *
 * Ein Verbrauchsschluessel traegt selbst keine Werte: die Mengen stehen an
 * der Kostenposition, je Einheit und je Wirtschaftsjahr. Einen davon je
 * Objekt anzulegen hiess deshalb, dieselbe leere Huelse in jedem Haus noch
 * einmal zu bauen. Es gibt jetzt genau einen, wie bei Flaeche und
 * Miteigentumsanteilen.
 *
 * Die Maszeinheit kommt an die Position, weil dieser eine Schluessel Wasser
 * in m³ und Waerme in kWh misst.
 */
final class Version20260911060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verbrauch als Systemschlüssel, Maßeinheit an der Kostenposition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_cost_item ADD measure VARCHAR(8) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO finance_distribution_key (id, property_id, name, kind, is_system)
            VALUES (gen_random_uuid(), NULL, 'Nach Verbrauch', 'metered', true)
        SQL);

        // Was schon auf einen eigenen Verbrauchsschlüssel zeigt, zeigt danach
        // auf den einen. Die erfassten Mengen hängen am Jahr und nicht am
        // Schlüssel — sie überstehen den Umzug unverändert.
        $this->addSql(<<<'SQL'
            UPDATE finance_cost_item
               SET distribution_key_id = (
                   SELECT id FROM finance_distribution_key
                    WHERE is_system = true AND kind = 'metered'
               )
             WHERE distribution_key_id IN (
                   SELECT id FROM finance_distribution_key
                    WHERE is_system = false AND kind = 'metered'
               )
        SQL);

        $this->addSql("DELETE FROM finance_distribution_key WHERE is_system = false AND kind = 'metered'");
    }

    /**
     * Es gibt keinen Weg zurueck.
     *
     * Das „up" loescht die eigenen Verbrauchsschluessel, nachdem es die
     * Positionen auf den Systemschluessel gezogen hat. Welcher Posten zu
     * welchem Schluessel gehoerte, steht danach nirgends mehr.
     *
     * Nur die Spalte wieder zu entfernen und Erfolg zu melden waere
     * schlimmer als ein verweigerter Rueckbau: der Bestand zeigte weiter auf
     * den Systemschluessel und waere nicht der Zustand von vorher — nur
     * saehe es so aus. Bei Finanzdaten ist ein stiller Teilerfolg
     * gefaehrlicher als ein Abbruch.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Die eigenen Verbrauchsschlüssel sind fort; welche Position zu welchem gehörte, '
            .'lässt sich nicht rekonstruieren. Zurück geht es nur über eine Sicherung.',
        );
    }
}
