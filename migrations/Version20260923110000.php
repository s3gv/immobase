<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Ausgangsablage fuer Webhooks.
 *
 * **Eine Ablage und kein Aufruf.** Waere der Webhook Teil des Speicherns,
 * haette die Erreichbarkeit eines fremden Prozesses ploetzlich etwas damit zu
 * tun, ob eine Kostenposition abgelegt werden kann. Stattdessen entsteht hier
 * eine Zeile, und ein eigener Lauf traegt sie aus.
 *
 * Die Zeile faellt mit zurueck, wenn der Vorgang zurueckgenommen wird, den
 * sie meldet: sie entsteht auf derselben Verbindung im selben Vorgang. Was
 * nicht geschehen ist, wird auch nicht gemeldet.
 *
 * Die Nutzlast traegt keine Fachdaten, nur den Hinweis — deshalb reicht ein
 * kurzes Textfeld, und deshalb steht in dieser Tabelle nichts, was nicht
 * ohnehin ueber `/api/v1/` abrufbar waere.
 */
final class Version20260923110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ausgangsablage für Webhooks an Plugins.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plugin_delivery (
            id UUID NOT NULL,
            plugin VARCHAR(32) NOT NULL,
            event VARCHAR(64) NOT NULL,
            payload TEXT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            deliver_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_error VARCHAR(300) NOT NULL DEFAULT \'\',
            PRIMARY KEY (id)
        )');

        // Gelesen wird immer dasselbe: was ist faellig und noch nicht
        // aufgegeben.
        $this->addSql('CREATE INDEX plugin_delivery_due ON plugin_delivery (deliver_at, attempts)');
        $this->addSql('CREATE INDEX plugin_delivery_plugin ON plugin_delivery (plugin)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plugin_delivery');
    }
}
