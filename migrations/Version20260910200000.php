<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kostenarten und Verteilerschluessel — die Sprache der Abrechnung.
 *
 * Beide werden mitgeliefert und nicht erwartet: eine leere Liste von
 * Kostenarten waere eine Aufgabe, die niemand bestellt hat, und die
 * siebzehn Positionen der BetrKV stehen in jedem Haus gleich.
 */
final class Version20260910200000 extends AbstractMigration
{
    /**
     * Die siebzehn Positionen des § 2 BetrKV, in ihrer Reihenfolge — sie ist
     * die gewohnte —, danach die ueblichen nicht umlagefaehigen.
     *
     * @var list<array{string, bool}>
     */
    private const array KINDS = [
        ['Grundsteuer', true],
        ['Wasserversorgung', true],
        ['Entwässerung', true],
        ['Heizung', true],
        ['Warmwasser', true],
        ['Verbundene Heizungs- und Warmwasseranlagen', true],
        ['Aufzug', true],
        ['Straßenreinigung und Müllbeseitigung', true],
        ['Gebäudereinigung und Ungezieferbekämpfung', true],
        ['Gartenpflege', true],
        ['Beleuchtung', true],
        ['Schornsteinreinigung', true],
        ['Sach- und Haftpflichtversicherung', true],
        ['Hauswart', true],
        ['Gemeinschaftsantenne und Breitbandnetz', true],
        ['Wascheinrichtungen', true],
        ['Sonstige Betriebskosten', true],
        ['Verwaltervergütung', false],
        ['Instandhaltung und Instandsetzung', false],
        ['Kontoführung und Bankgebühren', false],
        ['Zuführung zur Erhaltungsrücklage', false],
        ['Mietausfallwagnis', false],
    ];

    /**
     * Die berechneten Schluessel: ihre Werte stehen schon im System.
     *
     * @var list<array{string, string}>
     */
    private const array KEYS = [
        ['Wohn- und Nutzfläche', 'area'],
        ['Miteigentumsanteile', 'mea'],
        ['Personen im Haushalt', 'persons'],
        ['Nach Einheiten', 'units'],
    ];

    public function getDescription(): string
    {
        return 'Kostenarten und Verteilerschlüssel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_cost_kind (
                id UUID NOT NULL,
                name VARCHAR(120) NOT NULL,
                apportionable BOOLEAN NOT NULL,
                is_system BOOLEAN NOT NULL,
                ordering SMALLINT NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX finance_cost_kind_name ON finance_cost_kind (name)');

        $this->addSql(<<<'SQL'
            CREATE TABLE finance_distribution_key (
                id UUID NOT NULL,
                property_id UUID DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                is_system BOOLEAN NOT NULL,
                PRIMARY KEY(id),
                -- Ein Schlüssel gehört zu seinem Objekt und geht mit ihm.
                -- Anders als bei den Positionen hängt an ihm nichts, was
                -- ohne das Objekt noch einen Sinn hätte.
                CONSTRAINT finance_key_property FOREIGN KEY (property_id)
                    REFERENCES property (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX finance_key_property ON finance_distribution_key (property_id)');

        // Ein Name je Objekt, und die Systemschlüssel untereinander eindeutig.
        // Zwei Teilindizes, weil NULL in einem gewöhnlichen eindeutigen Index
        // nicht mit sich selbst kollidiert.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX finance_key_name_per_property ON finance_distribution_key (property_id, name)
                WHERE property_id IS NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX finance_key_name_global ON finance_distribution_key (name)
                WHERE property_id IS NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE finance_distribution_key_share (
                id UUID NOT NULL,
                key_id UUID NOT NULL,
                unit_id UUID NOT NULL,
                share NUMERIC(12, 4) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT finance_key_share_key FOREIGN KEY (key_id)
                    REFERENCES finance_distribution_key (id) ON DELETE CASCADE,
                -- RESTRICT wie überall bei Verweisen auf eine Einheit: was
                -- eine Abrechnung braucht, verschwindet nicht nebenbei.
                CONSTRAINT finance_key_share_unit FOREIGN KEY (unit_id)
                    REFERENCES property_unit (id) ON DELETE RESTRICT
            )
        SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX finance_key_share_once ON finance_distribution_key_share (key_id, unit_id)',
        );

        $this->seed();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_distribution_key_share');
        $this->addSql('DROP TABLE finance_distribution_key');
        $this->addSql('DROP TABLE finance_cost_kind');
    }

    /** Was in jedem Haus gleich heisst, steht von Anfang an da. */
    private function seed(): void
    {
        foreach (self::KINDS as $at => [$name, $apportionable]) {
            $this->addSql(
                'INSERT INTO finance_cost_kind (id, name, apportionable, is_system, ordering)
                 VALUES (gen_random_uuid(), ?, ?, true, ?)',
                [$name, $apportionable, $at + 1],
                [1 => ParameterType::BOOLEAN],
            );
        }

        foreach (self::KEYS as [$name, $kind]) {
            $this->addSql(
                'INSERT INTO finance_distribution_key (id, property_id, name, kind, is_system)
                 VALUES (gen_random_uuid(), NULL, ?, ?, true)',
                [$name, $kind],
            );
        }
    }
}
