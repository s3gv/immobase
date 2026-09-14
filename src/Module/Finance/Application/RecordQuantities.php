<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\CostItemYearUnit;
use App\Module\Finance\Domain\NothingToMeasure;
use App\Module\Finance\Domain\QuantityCannotBeCleared;
use App\Module\Finance\Domain\RecordedInTheMeantime;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Shared\Money\Money;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Die Mengen je Einheit zu einem Wirtschaftsjahr pflegen.
 *
 * Getrennt von den Jahreswerten, weil es zwei Sachen sind: dort steht, was
 * ein Jahr gekostet hat, hier, wie es sich auf die Einheiten verteilt. Auch
 * die Regeln haben nichts miteinander zu tun.
 */
final readonly class RecordQuantities
{
    public function __construct(
        private CostItemRepository $items,
        private UnitDirectory $units,
    ) {
    }

    /**
     * Die Mengen je Einheit setzen.
     *
     * Ein leeres Feld an einer Einheit, zu der noch nichts steht, heisst
     * „liegt nicht vor" — und das ist etwas anderes als eine Menge von null.
     * An einer erfassten Menge hiesse es, sie zu loeschen; das geht nicht,
     * siehe {@see self::set()}.
     *
     * Erlaubt sind nur die Einheiten des Objekts, zu dem die Position
     * gehoert. Die Oberflaeche zeigt ohnehin keine anderen — aber ein
     * abgeschicktes Formular ist Eingabe und keine Zusicherung, und bei
     * „fertig verteilt" flosse der Betrag einer untergeschobenen Einheit
     * unsichtbar in die Jahressumme ein.
     *
     * @param array<string, array{consumption: string, amount: ?Money}> $values
     *
     * @throws NothingToMeasure
     * @throws UnitBelongsElsewhere
     * @throws QuantityCannotBeCleared
     * @throws RecordedInTheMeantime
     * @throws InvalidArgumentException
     */
    public function meter(CostItemYear $year, array $values): void
    {
        $item = $year->item();

        if (!$item->isMetered()) {
            throw new NothingToMeasure();
        }

        $known = $this->units->byIds(array_keys($values));
        $recorded = self::byUnit($year);

        foreach ($values as $unitId => $value) {
            self::refuseForeignUnit($item, $known[$unitId] ?? null);
            $this->set($year, $recorded[$unitId] ?? null, $unitId, $value);
        }

        // Vor dem Speichern: ein kleinerer Einzelwert darf die Summe nicht
        // unter die Umsatzsteuer druecken, die in ihr stecken soll.
        $year->taxStillFits();

        try {
            $this->items->save($item);
        } catch (UniqueConstraintViolationException) {
            // Zwei gleichzeitig abgeschickte Formulare sehen beide noch
            // keine Menge zu einer Einheit und legen beide eine an.
            throw new RecordedInTheMeantime();
        }
    }

    /**
     * @throws UnitBelongsElsewhere
     */
    private static function refuseForeignUnit(CostItem $item, ?UnitBrief $unit): void
    {
        if (null === $unit || $unit->propertyId !== $item->propertyId()) {
            throw new UnitBelongsElsewhere();
        }
    }

    /**
     * Einen Wert setzen oder aendern — entfernt wird keiner.
     *
     * Aendern und nicht ersetzen: ein Entfernen und ein Anlegen in derselben
     * Runde laufen in den eindeutigen Index, weil Doctrine erst einfuegt und
     * dann loescht.
     *
     * Eine erfasste Menge laesst sich nicht leeren. Waere das erlaubt,
     * liesse sich ein Jahr Feld fuer Feld leerraeumen und danach ganz
     * entfernen: die Sperre am Jahreswert waere einen Umweg weit umgehbar,
     * und die Verteilungsgrundlage still fort.
     *
     * Wer eine erfasste Menge berichtigt, schreibt den richtigen Wert hin —
     * „kein Verbrauch" ist die Null und keine Leere.
     *
     * @param array{consumption: string, amount: ?Money} $value
     *
     * @throws QuantityCannotBeCleared
     * @throws InvalidArgumentException
     */
    private function set(CostItemYear $year, ?CostItemYearUnit $existing, string $unitId, array $value): void
    {
        if ('' === trim($value['consumption'])) {
            if (null !== $existing) {
                throw new QuantityCannotBeCleared();
            }

            return;
        }

        if (null === $existing) {
            new CostItemYearUnit($year, $unitId, $value['consumption'], $value['amount']);

            return;
        }

        $existing->record($value['consumption'], $value['amount']);
    }

    /**
     * @return array<string, CostItemYearUnit>
     */
    private static function byUnit(CostItemYear $year): array
    {
        $recorded = [];

        foreach ($year->units() as $unit) {
            $recorded[$unit->unitId()] = $unit;
        }

        return $recorded;
    }
}
