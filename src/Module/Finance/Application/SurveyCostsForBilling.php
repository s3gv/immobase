<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\CostDirectory;
use App\Module\Finance\Contract\CostRecord;
use App\Module\Finance\Contract\MeasureCost;
use App\Module\Finance\Contract\MeteredValue;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\CostItemYearUnit;

/**
 * Die Kosten eines Objektjahres, flach fuer die Abrechnung.
 *
 * Uebersetzt Entities in Datensaetze — und das ist die ganze Aufgabe. Was
 * daraus wird, entscheidet das Modul, das abrechnet.
 */
final readonly class SurveyCostsForBilling implements CostDirectory
{
    public function __construct(private CostItemRepository $items)
    {
    }

    public function forYear(string $propertyId, int $fiscalYear): array
    {
        $records = [];

        foreach ($this->items->forProperty($propertyId) as $item) {
            $year = $item->years()->forYear($fiscalYear);

            if (null !== $year) {
                $records[] = self::record($item, $year);
            }
        }

        return $records;
    }

    public function forMeasure(string $reference): array
    {
        $costs = [];

        foreach ($this->items->forMeasure($reference) as $item) {
            foreach ($item->years()->all() as $year) {
                $costs[] = new MeasureCost(
                    $item->number(),
                    $item->kind()->name(),
                    $year->fiscalYear(),
                    $year->amount(),
                );
            }
        }

        usort($costs, static fn (MeasureCost $one, MeasureCost $other): int => [$one->fiscalYear, $one->itemNumber]
            <=> [$other->fiscalYear, $other->itemNumber]);

        return $costs;
    }

    private static function record(CostItem $item, CostItemYear $year): CostRecord
    {
        $perUnit = [];

        foreach ($year->units() as $unit) {
            $perUnit[$unit->unitId()] = self::value($unit);
        }

        return new CostRecord(
            costYearId: $year->id(),
            itemNumber: $item->number(),
            costKindId: $item->kind()->id(),
            kindLabel: $item->kind()->name(),
            apportionable: $item->isApportionable(),
            splitsByDay: $item->splitsByDay(),
            keyId: $item->key()->id(),
            keyLabel: $item->key()->name(),
            keyKind: $item->key()->kind()->value,
            total: $year->amount(),
            inputTax: $year->inputTax(),
            measure: $item->measure()?->value,
            perUnit: $perUnit,
        );
    }

    private static function value(CostItemYearUnit $unit): MeteredValue
    {
        return new MeteredValue($unit->consumption(), $unit->amount());
    }
}
