<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ein Wirtschaftsplan geht vor der Versammlung heraus.
 *
 * Bisher kannte er zwei Zustaende — Entwurf und beschlossen — und damit fehlte
 * genau der, in dem ein Plan die meiste Zeit steht: **vorgelegt**. Ein Plan,
 * der erst mit dem Beschluss auf Papier erschiene, koennte gar nicht
 * beschlossen werden; die Eigentuemer bekommen ihn vorher zu lesen.
 *
 * Der Herausgabetag steht darum an ihm. Er sagt, was wann vorlag, und das ist
 * die erste Frage, wenn jemand ein Hausgeld bestreitet.
 *
 * Vorhandene Plaene haben keinen: es gab die Vorlage noch nicht. Ihr Zustand
 * bleibt, wie er ist — `draft` oder `released` heissen weiter dasselbe.
 */
final class Version20260912140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ein Wirtschaftsplan geht vor der Versammlung heraus';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_plan ADD proposed_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Was vorgelegt, aber noch nicht beschlossen war, kennt der alte
        // Zustandsraum nicht — es wird wieder zum Entwurf.
        $this->addSql("UPDATE billing_plan SET status = 'draft' WHERE status = 'proposed'");
        $this->addSql('ALTER TABLE billing_plan DROP proposed_on');
    }
}
