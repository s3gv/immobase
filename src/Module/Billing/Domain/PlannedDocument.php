<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Module\Finance\Contract\Interval;
use App\Shared\Money\Money;

/**
 * Ein Einzelwirtschaftsplan, so wie er beim Eigentuemer ankommt.
 *
 * Der Jahresanteil wird exakt verteilt — die Summe aller Einzelplaene ist auf
 * den Cent der Gesamtplan. Der Vorschuss dagegen ist jeden Monat derselbe
 * Betrag und muss darum gerundet werden; die Differenz von hoechstens einem
 * Cent je Zahlung gleicht die Jahresabrechnung aus. Beide Zahlen stehen auf
 * dem Blatt, damit niemand die eine fuer die andere haelt.
 */
final readonly class PlannedDocument
{
    /**
     * @param list<PlannedLine> $lines
     */
    public function __construct(
        public string $unitId,
        public int $unitNumber,
        public string $unitLabel,
        public string $recipientLabel,
        public string $recipientAddress,
        public array $lines,
        public Interval $interval,
    ) {
    }

    /** Die geplanten Kosten dieser Einheit, ohne die Ruecklage. */
    public function costs(): Money
    {
        return $this->sum(static fn (PlannedLine $line): bool => !$line->isReserve());
    }

    /** Die Zufuehrung zur Erhaltungsruecklage dieser Einheit. */
    public function reserve(): Money
    {
        return $this->sum(static fn (PlannedLine $line): bool => $line->isReserve());
    }

    /** Was die Einheit im Planjahr insgesamt traegt. */
    public function yearly(): Money
    {
        return $this->costs()->plus($this->reserve());
    }

    /** Was je Zahlung faellig wird — auf den vollen Cent aufgerundet. */
    public function advance(): Money
    {
        return $this->yearly()->eachOf($this->interval->timesAYear());
    }

    /** Eindeutig innerhalb eines Plans: je Einheit ein Schreiben. */
    public function key(): string
    {
        return $this->unitId;
    }

    /**
     * @param callable(PlannedLine): bool $wanted
     */
    private function sum(callable $wanted): Money
    {
        $total = Money::zero();

        foreach ($this->lines as $line) {
            if ($wanted($line)) {
                $total = $total->plus($line->amount);
            }
        }

        return $total;
    }
}
