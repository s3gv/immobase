<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wohin die Sonderumlage fliesst.
 *
 * Neu ist der Weg ueber die Erhaltungsruecklage: die Eigentuemer zahlen ein,
 * und die Rechnungen der Massnahme werden als Entnahme daraus bezahlt.
 *
 * Die vorhandenen Budgetplaene bekommen `measure` und nicht den neuen Weg —
 * auch die Erhaltungsmassnahmen unter ihnen. Was beschlossen wurde, wurde als
 * Vorschuss beschlossen und steht so in den Abrechnungen; eine Migration, die
 * beschlossene Sachverhalte umdeutet, aendert Schreiben, die herausgegangen
 * sind.
 */
final class Version20260914180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wohin die Sonderumlage fließt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE billing_budget ADD levy_purpose VARCHAR(16) DEFAULT 'measure' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_budget DROP levy_purpose');
    }
}
