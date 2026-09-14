<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Money\Money;

/**
 * Die Jahreswerte einer Kostenposition.
 *
 * Kein Datensatz, sondern ein Blick auf sie: „was kostete 2026" und „was ist
 * das juengste erfasste Jahr". Die Rechnung steht hier einmal und nicht in
 * Uebersicht, Kostenseite und Abrechnung jeweils neu.
 */
final readonly class CostItemYears
{
    /**
     * @param list<CostItemYear> $years das juengste zuerst
     */
    private function __construct(private array $years)
    {
    }

    /**
     * @param list<CostItemYear> $years
     */
    public static function of(array $years): self
    {
        usort($years, static fn (CostItemYear $one, CostItemYear $other): int => $other->fiscalYear() <=> $one->fiscalYear());

        return new self($years);
    }

    /** @return list<CostItemYear> */
    public function all(): array
    {
        return $this->years;
    }

    /** Ob zu irgendeinem Jahr schon Mengen je Einheit erfasst sind. */
    public function anyUnitsRecorded(): bool
    {
        foreach ($this->years as $year) {
            if ([] !== $year->units()) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return [] === $this->years;
    }

    public function forYear(int $fiscalYear): ?CostItemYear
    {
        foreach ($this->years as $year) {
            if ($year->fiscalYear() === $fiscalYear) {
                return $year;
            }
        }

        return null;
    }

    /** Was im laufenden Jahr erfasst ist — oder nichts. */
    public function amountFor(int $fiscalYear): ?Money
    {
        return $this->forYear($fiscalYear)?->amount();
    }

    public function latest(): ?CostItemYear
    {
        return $this->years[0] ?? null;
    }
}
