<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\ChosenMeasure;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemHasBeenRecorded;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DueDate;
use App\Module\Finance\Domain\KeyBelongsToAnotherProperty;
use App\Module\Finance\Domain\QuantitiesAreRecorded;
use App\Module\Finance\Domain\UnitOfMeasure;
use App\Module\Finance\Domain\UnknownProperty;
use App\Module\Property\Contract\PropertyDirectory;
use DateTimeImmutable;

/**
 * Eine Kostenposition anlegen und aendern.
 *
 * Jeder Schritt speichert sofort — wie beim Objekt und beim
 * Mietverhaeltnis. Das Objekt wird nachgeschlagen, bevor es uebernommen
 * wird: ohne das liesse sich ueber ein nachgebautes Formular jede beliebige
 * Kennung eintragen, und die Zeile waere danach nicht einmal mehr anzeigbar.
 */
final readonly class SaveCostItem
{
    public function __construct(
        private CostItemRepository $items,
        private PropertyDirectory $properties,
    ) {
    }

    /**
     * @throws UnknownProperty
     * @throws KeyBelongsToAnotherProperty
     */
    public function forProperty(string $propertyId, CostKind $kind, DistributionKey $key): CostItem
    {
        $this->refuseUnknownProperty($propertyId);
        self::refuseForeignKey($propertyId, $key);

        $item = new CostItem($this->items->nextNumber(), $propertyId, $kind, $key);
        $this->items->save($item);

        return $item;
    }

    /**
     * @throws UnknownProperty
     * @throws KeyBelongsToAnotherProperty
     * @throws QuantitiesAreRecorded
     */
    public function belongsTo(CostItem $item, string $propertyId, CostKind $kind, DistributionKey $key): void
    {
        $this->refuseUnknownProperty($propertyId);
        self::refuseForeignKey($propertyId, $key);
        self::refuseMovingRecordedUnits($item, $propertyId, $key);

        $item->belongsTo($propertyId, $kind, $key);
        $this->items->save($item);
    }

    /**
     * Womit gemessen wird — Schluessel und Maszeinheit in einem Zug.
     *
     * Beide gehoeren zusammen: wer den Schluessel von „Nach Verbrauch" weg
     * stellt, misst nichts mehr, und eine Maszeinheit ohne Mengen waere ein
     * Rest, den spaeter niemand erklaeren kann. Sie faellt dann weg.
     *
     * @throws KeyBelongsToAnotherProperty
     * @throws QuantitiesAreRecorded
     */
    public function measuredBy(CostItem $item, DistributionKey $key, ?UnitOfMeasure $measure): void
    {
        self::refuseForeignKey($item->propertyId(), $key);
        self::refuseMovingRecordedUnits($item, $item->propertyId(), $key);

        $item->belongsTo($item->propertyId(), $item->kind(), $key);
        $item->measureIn($item->isMetered() ? $measure : null);
        $this->items->save($item);
    }

    public function dueOn(CostItem $item, DueDate $due): void
    {
        $item->dueOn($due);
        $this->items->save($item);
    }

    /**
     * Umlagefaehigkeit, Verteilung und Notiz — was von der Regel abweicht.
     */
    public function noteThat(
        CostItem $item,
        ?bool $apportionable,
        bool $splitsByDay,
        string $note,
        ?ChosenMeasure $measure = null,
    ): void {
        $item->apportionAs($apportionable);
        $item->splitBy($splitsByDay);
        $item->noteThat($note);
        $item->paysFor($measure ?? ChosenMeasure::none());
        $this->items->save($item);
    }

    /**
     * Loeschen — nur, was nie in Kraft war.
     *
     * Sobald ein Jahreswert daran haengt, kann die Position Grundlage einer
     * Abrechnung sein: abgerechnet wird das vergangene Jahr, manchmal das
     * vorletzte. Dann ist Beenden die richtige Antwort. Ohne diese Pruefung
     * naehme ein Loeschen die Jahreswerte und alle erfassten Mengen
     * stillschweigend mit.
     *
     * @throws CostItemHasBeenRecorded
     */
    public function remove(CostItem $item): void
    {
        if (!$item->years()->isEmpty()) {
            throw new CostItemHasBeenRecorded();
        }

        $this->items->remove($item);
    }

    /** Beenden — oder mit `null` wieder aufnehmen. */
    public function runUntil(CostItem $item, ?DateTimeImmutable $day): void
    {
        $item->runsUntil($day);
        $this->items->save($item);
    }

    /**
     * Objekt und Schluessel liegen fest, sobald Mengen erfasst sind.
     *
     * Die Mengen haengen an Einheiten. Zoege die Position zu einem anderen
     * Objekt, blieben sie bei den Einheiten des alten: die Seite zeigte sie
     * nicht mehr, und bei „fertig verteilt" flossen ihre Betraege trotzdem
     * weiter in die Jahressumme. Dasselbe, wenn der Schluessel von „Nach
     * Verbrauch" weg gestellt wird — dann gibt es nichts mehr, wozu sie
     * gehoeren.
     *
     * Sie stillschweigend wegzuraeumen waere die falsche Antwort: es sind
     * erfasste Werte, und die koennen Grundlage einer Abrechnung sein. Wer
     * wirklich umhaengen will, entfernt erst die Jahre.
     *
     * @throws QuantitiesAreRecorded
     */
    private static function refuseMovingRecordedUnits(
        CostItem $item,
        string $propertyId,
        DistributionKey $key,
    ): void {
        $moves = $item->propertyId() !== $propertyId || $item->key()->id() !== $key->id();

        if ($moves && $item->years()->anyUnitsRecorded()) {
            throw new QuantitiesAreRecorded();
        }
    }

    /**
     * Ein eigener Schluessel verteilt auf die Einheiten seines Objekts —
     * anderswo verteilt er auf Einheiten, die es dort nicht gibt.
     *
     * @throws KeyBelongsToAnotherProperty
     */
    private static function refuseForeignKey(string $propertyId, DistributionKey $key): void
    {
        if (null !== $key->propertyId() && $key->propertyId() !== $propertyId) {
            throw new KeyBelongsToAnotherProperty();
        }
    }

    /**
     * @throws UnknownProperty
     */
    private function refuseUnknownProperty(string $propertyId): void
    {
        if ([] === $this->properties->byIds([$propertyId])) {
            throw UnknownProperty::of($propertyId);
        }
    }
}
