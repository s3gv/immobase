<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zwei Kostenarten fuer das Darlehen.
 *
 * **Zins und Tilgung getrennt, weil es zweierlei ist.** Der Zins ist Aufwand,
 * die Tilgung schichtet Vermoegen um: das Geld fliesst ab, aber die Schuld
 * sinkt um denselben Betrag. Eine gemeinsame Zeile „Darlehen" verschwiege
 * das, und die Eigentuemerversammlung beschloesse ueber eine Zahl, die zwei
 * Dinge zugleich ist.
 *
 * Mitgeliefert wie die siebzehn Positionen der BetrKV: der Wirtschaftsplan
 * belegt beide Zeilen aus dem gefuehrten Darlehen vor, und dafuer muss die
 * Kostenart in jedem Haus dieselbe sein. Umlagefaehig ist keine von beiden —
 * Kapitalkosten traegt der Eigentuemer, nicht der Mieter.
 */
final class Version20260915140000 extends AbstractMigration
{
    /** Dieselben Namen sucht Finance\Application\SurveyCatalogueForBilling. */
    private const array KINDS = ['Darlehenszinsen', 'Darlehenstilgung'];

    public function getDescription(): string
    {
        return 'Kostenarten für Darlehenszinsen und Darlehenstilgung';
    }

    public function up(Schema $schema): void
    {
        foreach (self::KINDS as $name) {
            // Die Reihenfolge haengt sich hinten an: welche Nummer die
            // letzte ist, weiss nur die Datenbank — wer hier 23 schriebe,
            // stritte mit jeder selbst angelegten Kostenart darum.
            $this->addSql(
                'INSERT INTO finance_cost_kind (id, name, apportionable, is_system, ordering)
                 SELECT gen_random_uuid(), ?, false, true, COALESCE(MAX(ordering), 0) + 1
                 FROM finance_cost_kind',
                [$name],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::KINDS as $name) {
            $this->addSql('DELETE FROM finance_cost_kind WHERE name = ? AND is_system = true', [$name]);
        }
    }
}
