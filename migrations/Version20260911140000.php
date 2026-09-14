<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ein Logo, und zwar in der Datenbank.
 *
 * Bisher trug die Zeile eine zufaellige Kennung — damit sind zwei Zeilen
 * moeglich, und zwei Logos sind kein Logo: zwei gleichzeitige erste Uploads
 * sehen beide „noch keines", und beim Entfernen des einen taucht das andere
 * wieder auf. Die Anwendung gab die Absage, die Datenbank liess es zu.
 *
 * Jetzt ist der Schluessel fest. Steht schon ein Logo da, behaelt es seinen
 * Platz; stehen wider Erwarten zwei, bleibt das juengste — dasselbe, das die
 * Anwendung bisher gezeigt hat.
 */
final class Version20260911140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ein Logo, und zwar in der Datenbank';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM settings_logo WHERE id NOT IN (
                SELECT id FROM settings_logo ORDER BY updated_at DESC LIMIT 1
            )
        SQL);
        $this->addSql('ALTER TABLE settings_logo ALTER COLUMN id TYPE VARCHAR(8) USING LEFT(id::text, 8)');
        $this->addSql("UPDATE settings_logo SET id = 'logo'");

        // Der Schluessel allein macht die Zeile eindeutig, nicht einzig: eine
        // zweite mit anderem Wert waere erlaubt. Diese Bedingung sagt, dass es
        // den einen Wert gibt und sonst keinen.
        $this->addSql("ALTER TABLE settings_logo ADD CONSTRAINT settings_logo_only CHECK (id = 'logo')");
    }

    /**
     * Zurueck zur zufaelligen Kennung.
     *
     * Das Logo bleibt stehen — es bekommt nur wieder eine Kennung, die zwei
     * Zeilen erlauben wuerde.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE settings_logo DROP CONSTRAINT settings_logo_only');
        $this->addSql('ALTER TABLE settings_logo ALTER COLUMN id TYPE UUID USING gen_random_uuid()');
    }
}
