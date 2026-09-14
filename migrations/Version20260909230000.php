<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Einmalige Codes werden ab jetzt mit Serverschluessel abgelegt.
 *
 * Bisher stand dort ein blosser SHA-256. Fuer die langen Links aus
 * random_bytes war das vertretbar, fuer die kurzen nicht: ein Code fuer den
 * zweiten Faktor hat eine Million Moeglichkeiten, ein Wiederherstellungscode
 * rund 50 Bit — beides ist aus einem Datenbankabzug in Minuten
 * zurueckgerechnet, ohne Ratenbegrenzung und ohne dass es jemand merkt.
 *
 * Die vorhandenen Zeilen lassen sich nicht umrechnen: dafuer braeuchte es die
 * Klartexte, und genau die gibt es nirgends. Sie werden deshalb geloescht.
 *
 * Was das bedeutet: offene Einladungs- und Reset-Links verfallen, und
 * Wiederherstellungscodes muessen unter "Mein Konto" neu erzeugt werden. Der
 * zweite Faktor selbst bleibt eingerichtet — sein Geheimnis liegt am Konto
 * und ist von dieser Aenderung nicht betroffen.
 */
final class Version20260909230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Einmalige Codes mit Serverschlüssel ablegen (alte Werte verfallen)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM auth_token');
        $this->addSql('DELETE FROM auth_recovery_code');
    }

    /**
     * Zurueck geht nichts: die alten Werte sind fort, und neue liessen sich
     * ohne Klartext ebenso wenig erzeugen. Der Rueckweg ist, neue Links zu
     * verschicken.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM auth_token');
        $this->addSql('DELETE FROM auth_recovery_code');
    }
}
