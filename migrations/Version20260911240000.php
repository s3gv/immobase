<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Der Anteil auf einer Abrechnungszeile ist Text.
 *
 * Er wird nie gerechnet, nur gezeigt. Eine `NUMERIC(14,3)` fuellte „78,40" auf
 * „78,400" auf — und dann stuende in der Vorschau etwas anderes als auf dem
 * zugestellten Schreiben. Was geprueft wurde, muss das sein, was ankommt.
 */
final class Version20260911240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Der Anteil auf einer Abrechnungszeile ist Text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_line
                ALTER COLUMN share_of TYPE VARCHAR(32),
                ALTER COLUMN share_total TYPE VARCHAR(32),
                ALTER COLUMN total_quantity TYPE VARCHAR(32)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE billing_line
                ALTER COLUMN share_of TYPE NUMERIC(14, 3) USING share_of::numeric,
                ALTER COLUMN share_total TYPE NUMERIC(14, 3) USING share_total::numeric,
                ALTER COLUMN total_quantity TYPE NUMERIC(14, 3) USING total_quantity::numeric
        SQL);
    }
}
