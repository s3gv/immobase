<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Distribution;
use App\Module\Billing\Domain\MissingFigure;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Finance\Contract\CostRecord;
use App\Module\Property\Contract\HouseholdWindow;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Tenancy\Contract\TenancySpan;
use App\Shared\Money\Money;
use App\Shared\Number\Decimal;
use DateTimeImmutable;

/**
 * Jede Kostenart auf die Einheiten verteilen.
 *
 * Die eine Stelle, an der aus einem Gesamtbetrag Anteile werden. Verteilt
 * wird ueber {@see Money::allocate()}: die Summe der Anteile ist **exakt** der
 * Gesamtbetrag, verbleibende Cent gehen der Reihe nach. Die Reihe ist die der
 * Einheitennummern und damit festgelegt — zwei Laeufe ueber dieselben Daten
 * ergeben denselben Cent an derselben Stelle.
 */
final readonly class ShareOutCosts
{
    public function __construct(private Weights $weights)
    {
    }

    /**
     * @param list<CostRecord>                     $costs
     * @param list<UnitBrief>                      $units      nach Nummer sortiert
     * @param array<string, list<TenancySpan>>     $spans
     * @param array<string, list<HouseholdWindow>> $households Personenzahl ohne Mietvertrag
     *
     * @return array{amounts: array<string, array<string, ProposedLine>>, missing: list<MissingFigure>}
     */
    public function of(
        array $costs,
        array $units,
        array $spans,
        array $households,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $amounts = [];
        $missing = [];

        foreach ($costs as $cost) {
            $resolved = $this->weights->of($cost, $units, $spans, $households, $from, $to);
            $missing = [...$missing, ...$resolved['missing']];

            foreach (self::linesOf($cost, $resolved['weights'], $resolved['shown']) as $unitId => $line) {
                $amounts[$unitId][$cost->costYearId] = $line;
            }
        }

        return ['amounts' => $amounts, 'missing' => $missing];
    }

    /**
     * Verteilt wird nach den Gewichten, ausgewiesen wird der Wert.
     *
     * Zwei Zahlen fuer dieselbe Sache, und das ist Absicht: `allocate()`
     * braucht ganze Zahlen, der Empfaenger braucht seine Quadratmeter. Die
     * eine ist die Skalierung der anderen — {@see \App\Module\Billing\Domain\Weight}.
     *
     * @param array<string, int>    $weights
     * @param array<string, string> $shown
     *
     * @return array<string, ProposedLine>
     */
    private static function linesOf(CostRecord $cost, array $weights, array $shown): array
    {
        if ([] === $weights) {
            return [];
        }

        $parts = $cost->total->allocate(array_values($weights));
        // Mit denselben Gewichten: so traegt jeder Anteil genau den Teil der
        // Steuer, der in ihm steckt, und die Teile gehen wieder im Ganzen auf.
        $taxes = $cost->inputTax->allocate(array_values($weights));
        $total = self::likeTheParts(Decimal::sum(array_values($shown)), array_values($shown));
        $lines = [];

        foreach (array_keys($weights) as $index => $unitId) {
            $lines[$unitId] = new ProposedLine(
                $cost->kindLabel,
                self::distributionOf($cost, $shown[$unitId] ?? '0', $total),
                $cost->total,
                $parts[$index] ?? Money::zero(),
                $cost->inputTax,
                $taxes[$index] ?? Money::zero(),
            );
        }

        return $lines;
    }

    /**
     * Die Summe bekommt so viele Nachkommastellen wie ihre Teile.
     *
     * `Decimal::sum()` kuerzt: aus 78,40 und 64,20 wird 142,6. Neben „78,40"
     * gelesen sieht das nach einem Tippfehler aus, und auf einem Schreiben,
     * das jemand nachrechnen soll, ist es einer zu viel.
     *
     * @param list<string> $parts
     */
    private static function likeTheParts(string $total, array $parts): string
    {
        $places = 0;

        foreach ($parts as $part) {
            $at = strpos($part, '.');
            $places = max($places, false === $at ? 0 : \strlen($part) - $at - 1);
        }

        if (0 === $places) {
            return $total;
        }

        // Aufgefuellt und nicht gerechnet: der Umweg ueber `float` waere
        // genau die Stelle, gegen die es Weight gibt.
        $dot = strpos($total, '.');
        $whole = false === $dot ? $total : substr($total, 0, $dot);
        $fraction = false === $dot ? '' : substr($total, $dot + 1);

        return $whole.'.'.str_pad(substr($fraction, 0, $places), $places, '0');
    }

    private static function distributionOf(CostRecord $cost, string $shareOf, string $shareTotal): Distribution
    {
        $distribution = Distribution::by(
            $cost->keyLabel,
            'billing.key.'.$cost->keyKind,
            $shareOf,
            $shareTotal,
        );

        // Die Masseinheit steht nur dort, wo der Wert wirklich eine Menge
        // ist. Hat der Dienstleister schon verteilt, ist er ein Betrag —
        // „277,40 von 480,00 m³" waere schlicht falsch.
        return null === $cost->measure || self::providerDistributed($cost)
            ? $distribution
            : $distribution->measuring($shareTotal, $cost->measure);
    }

    /** Steht bei den Einheitenwerten ein Betrag, hat jemand anders verteilt. */
    private static function providerDistributed(CostRecord $cost): bool
    {
        foreach ($cost->perUnit as $value) {
            if (null !== $value->amount) {
                return true;
            }
        }

        return false;
    }
}
