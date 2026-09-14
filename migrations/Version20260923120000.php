<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Kacheln, die ein Plugin auf die Uebersicht stellt.
 *
 * **Geschoben und nicht geholt.** Das Plugin rechnet, wann es will, und legt
 * den fertigen Wert hier ab; die Uebersicht liest nur aus der eigenen
 * Datenbank. Fragte sie beim Seitenaufbau jedes Plugin, machte ein einziges
 * langsames die Startseite langsam — und ein totes machte sie kaputt.
 *
 * Der Wert kommt fertig geschrieben an, so wie ihn auch ein Modul liefert:
 * nur das Plugin weiss, ob seine Zahl ein Betrag ist, eine Anzahl oder ein
 * Anteil.
 *
 * `seen_at` ist die Frist: was zu alt ist, verschwindet still. Eine Kachel,
 * die eine tote Zahl zeigt, ist schlimmer als keine Kachel.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kacheln, die Plugins auf die Übersicht stellen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plugin_tile (
            id UUID NOT NULL,
            plugin VARCHAR(32) NOT NULL,
            key VARCHAR(64) NOT NULL,
            labels TEXT NOT NULL,
            value VARCHAR(64) NOT NULL,
            tone VARCHAR(16) NOT NULL,
            path VARCHAR(200) NOT NULL,
            seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        // Ein Schluessel je Plugin: eine zweite Kachel mit demselben Namen
        // waere zweimal dieselbe Zahl.
        $this->addSql('CREATE UNIQUE INDEX plugin_tile_key ON plugin_tile (plugin, key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plugin_tile');
    }
}
