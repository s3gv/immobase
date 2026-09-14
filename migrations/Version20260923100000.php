<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aktivierte Plugins.
 *
 * **Eine Zeile je Installation, mit dem bestaetigten Manifest darin.** Nicht
 * als Verweis auf die Datei: die Navigation liest bei jedem Seitenaufbau, und
 * eine Anwendung, die dafuer Verzeichnisse abtastet, wird mit jedem Plugin
 * langsamer. Und was jemand bestaetigt hat, steht damit fest — aendert die
 * Datei sich, wird erneut gefragt, statt dass die Zustimmung stillschweigend
 * mitwaechst.
 *
 * Das Token liegt nur als Abdruck da; es entsteht bei jedem Start des
 * Plugin-Prozesses neu. Das Passwort der Datenbankrolle liegt im Klartext,
 * weil der Core es dem Plugin
 * auf Nachfrage wieder aushaendigen muss — es ist kein Ausweis eines
 * Menschen, sondern die Zugangsangabe zu einem Schema, das ohnehin nur diesem
 * Plugin gehoert.
 *
 * **Was hier nicht steht:** die Tabellen des Plugins. Die liegen in seinem
 * eigenen Schema `plugin_<name>`, das beim Aktivieren entsteht und beim
 * Entfernen mitgeht. Migrationen des Cores fassen es nicht an.
 */
final class Version20260923100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aktivierte Plugins mit ihrem bestätigten Manifest.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plugin_installation (
            id UUID NOT NULL,
            name VARCHAR(32) NOT NULL,
            version VARCHAR(16) NOT NULL,
            manifest TEXT NOT NULL,
            state VARCHAR(16) NOT NULL,
            token_hash VARCHAR(128) NOT NULL,
            storage_password VARCHAR(128) NOT NULL,
            activated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            activated_by VARCHAR(200) NOT NULL,
            port INT NOT NULL,
            PRIMARY KEY (id)
        )');

        // Ein Plugin ist durch seinen Namen bestimmt: er traegt das Schema,
        // die Adresse und die Rechte. Zwei gleichnamige Installationen waeren
        // zwei Wahrheiten ueber dasselbe Verzeichnis.
        $this->addSql('CREATE UNIQUE INDEX plugin_installation_name ON plugin_installation (name)');

        // Jedes Plugin laeuft als eigener Prozess auf einem eigenen Port im
        // Anwendungscontainer. Zwei auf demselben Port — der zweite startete
        // nie, und der Proxy fragte das falsche.
        $this->addSql('CREATE UNIQUE INDEX plugin_installation_port ON plugin_installation (port)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plugin_installation');
    }
}
