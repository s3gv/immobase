<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

/**
 * Die vier Auswertungen.
 *
 * **Vier und kein Berichtsgenerator.** Was hier steht, sind Fragen, die
 * jemand wirklich stellt — „was ist teurer geworden", „wer zahlt spaet", „was
 * steht leer", „wie steht die Ruecklage". Ein Generator, mit dem sich alles
 * bauen laesst, beantwortet keine davon; er verschiebt die Arbeit nur zum
 * Leser.
 *
 * Gerechnet wird in der Datenbank, weil sie es besser kann — aber ohne
 * Gleitkomma: `numeric` bleibt `numeric`, und Betraege gehen als Zeichenkette
 * hinaus, wie sie hereingekommen sind.
 */
final readonly class Reports
{
    public function __construct(private Store $store)
    {
    }

    /**
     * Kostenentwicklung: die Summe je Jahr und die Arten des letzten Jahres.
     *
     * Zwei Blickwinkel auf dieselbe Frage. Die Summe je Jahr zeigt die
     * Richtung — das ist das Einzige, was ein Diagramm besser kann als eine
     * Tabelle. Die Arten zeigen, woran es liegt.
     *
     * @return array{totals: list<array<string, mixed>>, kinds: list<array<string, mixed>>, year: ?int}
     */
    public function costTrend(): array
    {
        $totals = $this->store->rows(
            'SELECT fiscal_year, SUM(amount) AS amount FROM cost_year
             GROUP BY fiscal_year ORDER BY fiscal_year',
        );

        $year = [] === $totals ? null : (int) $totals[\count($totals) - 1]['fiscal_year'];

        $kinds = null === $year ? [] : $this->store->rows(
            'SELECT cost_kind, SUM(amount) AS amount FROM cost_year
             WHERE fiscal_year = :year
             GROUP BY cost_kind ORDER BY SUM(amount) DESC LIMIT 8',
            ['year' => $year],
        );

        return ['totals' => $totals, 'kinds' => $kinds, 'year' => $year];
    }

    /**
     * Offene Forderungen nach Alter — und wie lange bis zum Ausgleich.
     *
     * @return array{buckets: list<array<string, mixed>>, settled: array<string, mixed>}
     */
    public function claims(): array
    {
        $buckets = $this->store->rows(
            "SELECT CASE
                    WHEN due_on > CURRENT_DATE - INTERVAL '30 days' THEN 'bis 30 Tage'
                    WHEN due_on > CURRENT_DATE - INTERVAL '90 days' THEN '31 bis 90 Tage'
                    ELSE 'über 90 Tage'
                END AS age,
                COUNT(*) AS claims, SUM(open_amount) AS amount
             FROM claim WHERE settled_on IS NULL
             GROUP BY age ORDER BY age",
        );

        $settled = $this->store->rows(
            'SELECT COUNT(*) AS claims, ROUND(AVG(settled_on::date - due_on::date), 1) AS days
             FROM claim WHERE settled_on IS NOT NULL AND due_on IS NOT NULL',
        );

        return ['buckets' => $buckets, 'settled' => $settled[0] ?? ['claims' => 0, 'days' => null]];
    }

    /**
     * Vermietung: was steht, was steht leer.
     *
     * Gezaehlt wird ueber das laufende Mietverhaeltnis und nicht ueber ein
     * Kennzeichen an der Einheit — ein Kennzeichen ist gepflegt oder nicht,
     * ein Mietverhaeltnis laeuft oder laeuft nicht.
     *
     * @return list<array<string, mixed>>
     */
    public function occupancy(): array
    {
        return $this->store->rows(
            "SELECT p.name AS property,
                    COUNT(u.id) AS units,
                    COUNT(t.id) AS rented,
                    COUNT(u.id) - COUNT(t.id) AS vacant,
                    COALESCE(SUM(u.area), 0) AS area
             FROM property p
             LEFT JOIN unit u ON u.property_id = p.id AND u.status <> 'handed_over'
             LEFT JOIN tenancy t ON t.unit_id = u.id AND t.status = 'active'
             GROUP BY p.name ORDER BY p.name",
        );
    }

    /**
     * Ruecklage und Darlehen je Gemeinschaft.
     *
     * Der Stand ist die Summe der Bewegungen ohne die stornierten — er wird
     * gerechnet und nicht gespeichert, damit er nicht von dem abweichen kann,
     * woraus er entsteht.
     *
     * @return array{reserves: list<array<string, mixed>>, loans: list<array<string, mixed>>}
     */
    public function reservesAndLoans(): array
    {
        return [
            'reserves' => $this->store->rows(
                'SELECT p.name AS property, COALESCE(SUM(r.effect), 0) AS balance, COUNT(r.id) AS movements
                 FROM property p LEFT JOIN reserve r ON r.property_id = p.id AND r.reversed = false
                 GROUP BY p.name ORDER BY p.name',
            ),
            'loans' => $this->store->rows(
                'SELECT p.name AS property, l.label, l.lender, l.amount, l.rate_bps, l.starts_on
                 FROM loan l JOIN property p ON p.id = l.property_id
                 ORDER BY p.name, l.label',
            ),
        ];
    }
}
