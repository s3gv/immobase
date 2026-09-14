<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\PlannedDocument;
use App\Module\Billing\Domain\PlannedLine;
use App\Shared\Money\Money;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\Sheet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gesamtwirtschaftsplan und Einzelwirtschaftsplan in einer Aufstellung.
 *
 * Getrennt gedruckt waeren es zwei Tabellen mit denselben Zeilen und einer
 * Spalte Unterschied. Zusammen ist es die Aufstellung, die ein Eigentuemer
 * nachrechnen kann: je Position der Betrag der Gemeinschaft, daneben der des
 * Vorjahres, darunter der Schluessel mit seiner Bezugsgroesse — und rechts
 * der eigene Anteil.
 *
 * Die Zufuehrung zur Erhaltungsruecklage steht danach und mit eigener
 * Zwischensumme: ueber sie wird nach § 28 Abs. 1 WEG getrennt beschlossen.
 */
final readonly class PlanTable
{
    public function __construct(
        private TranslatorInterface $translator,
        private Amounts $amounts,
        private Shares $shares,
    ) {
    }

    public function of(Sheet $sheet, PlannedDocument $planned, float $at): float
    {
        $at = $this->block($sheet, $at, 'billing.plan.pdf.costs', self::costLines($planned), named: true);
        $at = $this->sum($sheet, $at, 'billing.plan.pdf.costs_sum', $planned->costs());

        $reserve = self::reserveLines($planned);

        if ([] !== $reserve) {
            // Ohne Zwischensumme: es ist eine Zeile, und ihre Ueberschrift
            // sagt schon, was sie ist. Dreimal dasselbe Wort untereinander
            // liest niemand, es sieht nur nach mehr aus.
            $at = $this->block($sheet, $at + 6.0, 'billing.plan.line_kind.reserve', $reserve, named: false);
        }

        return $this->sum($sheet, $at + 4.0, 'billing.plan.pdf.yearly', $planned->yearly()) + 6.0;
    }

    /**
     * @param list<PlannedLine> $lines
     */
    private function block(Sheet $sheet, float $at, string $heading, array $lines, bool $named): float
    {
        $at = $sheet->heading($at, $this->translator->trans($heading));

        foreach ($lines as $line) {
            $at = $this->line($sheet, $at, $line, $named);
        }

        return $at;
    }

    private function line(Sheet $sheet, float $at, PlannedLine $line, bool $named): float
    {
        if ($named) {
            $sheet->put(Sheet::LEFT, $at, $line->costKind, 9.0);
        }

        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($line->amount), 9.0);

        return $sheet->putLines(Sheet::LEFT + 2.0, $at + 4.5, 120.0, $this->explain($line), 7.5) + 1.0;
    }

    /**
     * Der Rechenweg unter der Zeile.
     *
     * Bei einer Zeile, die nichts plant, steht kein Schluessel: geteilt wurde
     * nichts, und „0 von 0" waere eine Rechnung, die nie stattfand. Der
     * Vorjahreswert steht trotzdem da — er ist der Grund, warum die Zeile
     * ueberhaupt aufgefuehrt wird.
     */
    private function explain(PlannedLine $line): string
    {
        $parts = [$this->translator->trans('billing.plan.pdf.last_year', [
            '%amount%' => $this->amounts->money($line->previous),
        ])];

        if (!$line->isNothing()) {
            array_unshift($parts, \sprintf(
                '%s %s',
                $this->translator->trans('billing.pdf.of'),
                $this->amounts->money($line->total),
            ));
            $parts[] = $this->shares->of($line->distribution);
        }

        if ('' !== $line->reason) {
            $parts[] = $line->reason;
        }

        return implode(' — ', $parts);
    }

    private function sum(Sheet $sheet, float $at, string $label, Money $amount): float
    {
        $sheet->rule($at);
        $sheet->put(Sheet::LEFT, $at + 1.0, $this->translator->trans($label), 9.0, 'B');
        $sheet->putRight(210.0 - Sheet::RIGHT, $at + 1.0, $this->amounts->money($amount), 9.0, 'B');

        return $at + 8.0;
    }

    /**
     * @return list<PlannedLine>
     */
    private static function costLines(PlannedDocument $document): array
    {
        return array_values(array_filter(
            $document->lines,
            static fn (PlannedLine $line): bool => !$line->isReserve(),
        ));
    }

    /**
     * @return list<PlannedLine>
     */
    private static function reserveLines(PlannedDocument $document): array
    {
        return array_values(array_filter(
            $document->lines,
            static fn (PlannedLine $line): bool => $line->isReserve(),
        ));
    }
}
