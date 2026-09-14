<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

/**
 * Die vier Zahlen, die auf die Uebersicht des Cores gehen.
 *
 * **Nur Zahlen, zu denen es eine Handlung gibt.** „Objekte gesamt" waere
 * Ballast: niemand tut etwas anders, weil dort eine Zahl steht. „Über 90 Tage
 * offen" schon.
 *
 * Geschoben wird nach jeder Spiegelung. Bleibt das Plugin stehen, altern die
 * Kacheln und verschwinden nach einem Tag von selbst — der Core zeigt dann
 * lieber nichts als eine tote Zahl.
 */
final readonly class Tiles
{
    public function __construct(
        private Store $store,
        private Reports $reports,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return [
            $this->overdue(),
            $this->vacancy(),
            $this->costTrend(),
            $this->reserve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overdue(): array
    {
        $rows = $this->store->rows(
            "SELECT COALESCE(SUM(open_amount), 0)::text AS amount FROM claim
             WHERE settled_on IS NULL AND due_on < CURRENT_DATE - INTERVAL '90 days'",
        );

        $amount = self::text($rows, 'amount');

        return [
            'key' => 'long_overdue',
            'label' => ['de' => 'Über 90 Tage offen', 'en' => 'Overdue past 90 days'],
            'value' => Decimal::format($amount, 2).' €',
            'tone' => Decimal::isPositive($amount) ? 'danger' : 'success',
            'path' => '/berichte',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vacancy(): array
    {
        $vacant = 0;
        $units = 0;

        foreach ($this->reports->occupancy() as $row) {
            $vacant += (int) $row['vacant'];
            $units += (int) $row['units'];
        }

        return [
            'key' => 'vacancy',
            'label' => ['de' => 'Leerstand', 'en' => 'Vacancy'],
            'value' => 0 === $units ? '—' : \sprintf('%s %% (%d)', number_format($vacant / $units * 100, 1, ',', '.'), $vacant),
            'tone' => $vacant > 0 ? 'warning' : 'success',
            'path' => '/berichte',
        ];
    }

    /**
     * Die Kosten dieses Jahres gegen die des Vorjahres.
     *
     * **Ohne Vorjahr keine Zahl.** „+100 %" gegen nichts ist keine
     * Steigerung, sondern ein erster Wert — und eine erfundene Bezugsgroesse
     * ist schlechter als keine.
     *
     * Gerechnet in der Datenbank, mit NUMERIC: die Veraenderung zweier
     * Geldsummen soll nicht an einer Gleitkommazahl haengen.
     *
     * @return array<string, mixed>
     */
    private function costTrend(): array
    {
        $rows = $this->store->rows(
            'WITH years AS (
                 SELECT fiscal_year, SUM(amount) AS amount FROM cost_year
                 GROUP BY fiscal_year ORDER BY fiscal_year DESC LIMIT 2
             )
             SELECT ROUND((n.amount - b.amount) / b.amount * 100, 1)::text AS change
             FROM years n, years b
             WHERE n.fiscal_year = (SELECT MAX(fiscal_year) FROM years)
               AND b.fiscal_year < n.fiscal_year
               AND b.amount > 0',
        );

        $change = self::text($rows, 'change');
        $known = '' !== $change;

        return [
            'key' => 'cost_trend',
            'label' => ['de' => 'Kosten ggü. Vorjahr', 'en' => 'Costs vs. last year'],
            'value' => $known ? self::percent($change) : '—',
            'tone' => $known && Decimal::isPositive($change) ? 'warning' : 'neutral',
            'path' => '/berichte',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reserve(): array
    {
        $rows = $this->store->rows('SELECT COALESCE(SUM(effect), 0)::text AS balance FROM reserve WHERE reversed = false');
        $balance = self::text($rows, 'balance');

        return [
            'key' => 'reserve',
            'label' => ['de' => 'Erhaltungsrücklage', 'en' => 'Maintenance reserve'],
            'value' => Decimal::format($balance, 2).' €',
            'tone' => Decimal::isNegative($balance) ? 'danger' : 'neutral',
            'path' => '/berichte',
        ];
    }

    /** Mit Vorzeichen und deutschem Komma — sonst steht eine Kachel anders da als die daneben. */
    private static function percent(string $change): string
    {
        return (Decimal::isNegative($change) ? '−' : '+').Decimal::format(ltrim($change, '+-'), 1).' %';
    }

    /**
     * Der Wert der ersten Zeile als Zeichenkette — NUMERIC kommt so aus PDO.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function text(array $rows, string $column): string
    {
        $value = $rows[0][$column] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
