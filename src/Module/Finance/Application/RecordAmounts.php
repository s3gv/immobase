<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\StatementSources;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\EntryMode;
use App\Module\Finance\Domain\QuantitiesAreRecorded;
use App\Module\Finance\Domain\UsedByAStatement;
use App\Module\Finance\Domain\YearAlreadyRecorded;
use App\Shared\Money\Money;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Die Jahreswerte einer Kostenposition pflegen.
 *
 * Eine Regel haelt sie lesbar: je Wirtschaftsjahr hoechstens ein Wert. Ohne
 * sie waere nicht entscheidbar, welcher gilt — und die Abrechnung eines
 * vergangenen Jahres haette zwei Antworten.
 *
 * Die Mengen je Einheit stehen daneben, in {@see RecordQuantities}: dort
 * geht es darum, wie sich ein Jahr verteilt, hier nur darum, was es
 * gekostet hat.
 */
final readonly class RecordAmounts
{
    public function __construct(
        private CostItemRepository $items,
        private StatementSources $statements,
    ) {
    }

    /**
     * @throws YearAlreadyRecorded
     * @throws InvalidArgumentException
     */
    public function add(CostItem $item, int $fiscalYear, Money $amount, EntryMode $mode, ?Money $inputTax = null): CostItemYear
    {
        $this->refuseTwice($item, $fiscalYear, null);

        $year = new CostItemYear($item, $fiscalYear, $amount, $mode);
        $year->containsTax($inputTax ?? Money::zero());
        $this->saved($item, static fn (): YearAlreadyRecorded => YearAlreadyRecorded::inTheMeantime());

        return $year;
    }

    /**
     * @throws YearAlreadyRecorded
     * @throws InvalidArgumentException
     */
    public function change(CostItemYear $year, int $fiscalYear, Money $amount, EntryMode $mode, ?Money $inputTax = null): void
    {
        $this->refuseTwice($year->item(), $fiscalYear, $year);

        $year->moveTo($fiscalYear);
        $year->cost($amount, $mode);
        $year->containsTax($inputTax ?? Money::zero());
        $this->saved($year->item(), static fn (): YearAlreadyRecorded => YearAlreadyRecorded::inTheMeantime());
    }

    /**
     * Einen Jahreswert entfernen — nur, solange nichts darauf steht.
     *
     * Mit dem Jahr gingen seine Mengen je Einheit mit: das Orphan-Removal
     * raeumt sie stillschweigend weg. Damit liesse sich die Sperre an der
     * Kostenposition umgehen — die Position bliebe stehen, ihre
     * Verteilungsgrundlage waere fort.
     *
     * Dieselbe Grenze wie eine Ebene darueber: geloescht wird, worauf nichts
     * aufbaut. Ein Betrag, den noch niemand verteilt hat, ist eine
     * Fehleingabe; einer mit Mengen darunter ist Abrechnungsstoff. Ein
     * falscher Betrag oder ein falsches Jahr werden geaendert, nicht
     * geloescht — dafuer gibt es {@see self::change()}.
     *
     * @throws QuantitiesAreRecorded
     */
    /**
     * @throws QuantitiesAreRecorded
     * @throws UsedByAStatement
     */
    public function drop(CostItemYear $year): void
    {
        if ([] !== $year->units()) {
            throw new QuantitiesAreRecorded();
        }

        $held = $this->statements->usedByAStatement([$year->id()])[$year->id()] ?? null;

        if (null !== $held) {
            throw UsedByAStatement::under($held);
        }

        $item = $year->item();
        $item->remove($year);
        $this->items->save($item);
    }

    /**
     * Speichern — und die Absage der Datenbank in die des Fachs uebersetzen.
     *
     * Zwischen der Pruefung oben und dem Speichern liegt eine Luecke. Zwei
     * gleichzeitig abgeschickte Formulare lesen darin denselben Stand, und
     * der zweite laeuft in den eindeutigen Index. Die Anwendung gibt die
     * lesbare Absage, die Datenbank bleibt die letzte Linie — aber ihre
     * Meldung ist keine Antwort, die jemand versteht.
     *
     * @param callable(): YearAlreadyRecorded $refusal
     *
     * @throws YearAlreadyRecorded
     */
    private function saved(CostItem $item, callable $refusal): void
    {
        try {
            $this->items->save($item);
        } catch (UniqueConstraintViolationException) {
            throw $refusal();
        }
    }

    /**
     * @throws YearAlreadyRecorded
     */
    private function refuseTwice(CostItem $item, int $fiscalYear, ?CostItemYear $itself): void
    {
        $existing = $item->years()->forYear($fiscalYear);

        if (null !== $existing && $existing !== $itself) {
            throw YearAlreadyRecorded::of($fiscalYear);
        }
    }
}
