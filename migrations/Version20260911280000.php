<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Je Abrechnungsnummer hoechstens ein offener Entwurf.
 *
 * Die Anwendung fuehrt einen zweiten Klick auf „Korrektur erzeugen" in den
 * vorhandenen Entwurf statt einen zweiten anzulegen. Zwei Menschen im selben
 * Augenblick sehen aber beide „noch keiner" — die lesbare Absage gibt die
 * Anwendung, die letzte Linie ist die Datenbank.
 *
 * Ein teilweiser Index und keine Bedingung ueber die ganze Tabelle: eine
 * Nummer darf beliebig viele *freigegebene* Iterationen haben, das ist der
 * Sinn von Korrekturen. Offen sein darf zu jeder Zeit nur eine.
 */
final class Version20260911280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Je Abrechnungsnummer höchstens ein offener Entwurf';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX billing_statement_one_draft
                ON billing_statement (number)
             WHERE status = 'draft'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX billing_statement_one_draft');
    }
}
