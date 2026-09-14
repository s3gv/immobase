<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\CostBreakdown;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Money\Money;

/**
 * Wo die Kosten liegen — fuer die Uebersicht der Finanzen.
 *
 * Gerechnet wird in PHP und nicht in SQL: bei „fertig verteilt" ist der
 * Betrag eines Jahres die Summe seiner Einzelbetraege, und diese Regel steht
 * im Modell. Sie ein zweites Mal als Abfrage zu schreiben hiesse, sie
 * zweimal zu pflegen — und zwei Fassungen derselben Regel laufen
 * auseinander.
 *
 * Die Positionen kommen dafuer in einem Zug mitsamt ihren Jahren; die
 * Uebersicht fasst ohnehin jede an.
 */
final readonly class SurveyCosts
{
    public function __construct(
        private CostItemRepository $items,
        private PropertyDirectory $properties,
    ) {
    }

    /**
     * Die Uebersicht: welche Jahre es gibt, und die Zahlen zu einem davon.
     *
     * Beides in einem Zug, weil beides denselben Bestand braucht — zweimal
     * laden hiesse, dieselbe Frage zweimal zu stellen.
     *
     * Zur Wahl steht, was es auch wirklich gibt. Ist noch nichts erfasst,
     * steht das laufende Jahr da: eine leere Auswahl waere schlechter als
     * eine leere Uebersicht. Ein Jahr, zu dem es nichts gibt, wird auf das
     * juengste zurechtgerueckt — es kommt aus der Adresszeile und ist damit
     * Eingabe.
     *
     * @return array{years: non-empty-list<int>, breakdown: CostBreakdown}
     */
    public function overview(int $currentYear, ?int $chosen): array
    {
        $items = $this->items->all();
        $years = self::yearsIn($items, $currentYear);
        $fiscalYear = \in_array($chosen, $years, true) ? $chosen : $years[0];

        return ['years' => $years, 'breakdown' => $this->sum($items, $fiscalYear)];
    }

    /**
     * @param list<CostItem> $items
     *
     * @return non-empty-list<int>
     */
    private static function yearsIn(array $items, int $currentYear): array
    {
        $years = [];

        foreach ($items as $item) {
            foreach ($item->years()->all() as $year) {
                $years[$year->fiscalYear()] = true;
            }
        }

        if ([] === $years) {
            return [$currentYear];
        }

        $found = array_keys($years);
        rsort($found);

        return $found;
    }

    /**
     * @param list<CostItem> $items
     */
    private function sum(array $items, int $fiscalYear): CostBreakdown
    {
        $total = Money::zero();
        $apportionable = Money::zero();
        $counted = 0;
        $byKind = [];
        $byProperty = [];
        $names = $this->propertyNames();

        foreach ($items as $item) {
            $amount = $item->years()->amountFor($fiscalYear);

            if (null === $amount) {
                continue;
            }

            ++$counted;
            $total = $total->plus($amount);
            $apportionable = $item->isApportionable() ? $apportionable->plus($amount) : $apportionable;

            $kind = $item->kind()->name();
            $byKind[$kind] = ($byKind[$kind] ?? Money::zero())->plus($amount);

            $property = $names[$item->propertyId()] ?? $item->propertyId();
            $byProperty[$property] = ($byProperty[$property] ?? Money::zero())->plus($amount);
        }

        return CostBreakdown::of($fiscalYear, $total, $apportionable, $counted, $byKind, $byProperty);
    }

    /** @return array<string, string> */
    private function propertyNames(): array
    {
        $names = [];

        foreach ($this->properties->all() as $property) {
            $names[$property->id] = $property->oneLine();
        }

        return $names;
    }
}
