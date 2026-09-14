<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;

/**
 * Zugriff auf die Kostenpositionen.
 */
interface CostItemRepository
{
    public function save(CostItem $item): void;

    public function remove(CostItem $item): void;

    public function byId(string $id): ?CostItem;

    public function byNumber(int $number): ?CostItem;

    /**
     * Die naechste freie Nummer, ab 40001.
     *
     * Aus einer Sequenz und nicht aus MAX(number) + 1: zwei gleichzeitige
     * Anlagen lesen sonst denselben Hoechststand.
     */
    public function nextNumber(): int;

    public function countMatching(CostItemFilter $filter): int;

    /**
     * @return list<CostItem>
     */
    public function matching(CostItemFilter $filter, Page $page, Sort $sort): array;

    /**
     * Wie viele Positionen an diesen Objekten haengen.
     *
     * Fuer die Objektuebersicht — gefragt wird fuer eine ganze Seite auf
     * einmal, nicht je Zeile.
     *
     * @param list<string> $propertyIds
     *
     * @return array<string, int>
     */
    public function countsFor(array $propertyIds): array;

    /**
     * Ob dieser Verteilerschluessel irgendwo benutzt wird.
     *
     * Die Anwendung fragt vor dem Loeschen; der Fremdschluessel in der
     * Datenbank ist die letzte Grenze.
     */
    public function anyUsing(string $keyId): bool;

    /**
     * Alle Positionen mit ihren Jahreswerten — fuer die Uebersicht.
     *
     * Gerechnet wird danach in PHP und nicht in SQL: bei „fertig verteilt"
     * ist der Betrag eines Jahres die Summe seiner Einzelbetraege, und diese
     * Regel steht im Modell. Sie ein zweites Mal als Abfrage zu schreiben
     * hiesse, sie zweimal zu pflegen.
     *
     * @return list<CostItem>
     */
    public function all(): array;

    /**
     * Alle Kostenpositionen eines Objekts, mit ihren Jahren und Verbraeuchen.
     *
     * Fuer die Abrechnung: sie fragt einmal je Objekt und Jahr und geht dann
     * alles durch. Mitgeladen wird, was sie ohnehin anfasst — sonst waere es
     * je Position eine weitere Abfrage.
     *
     * @return list<CostItem>
     */
    public function forProperty(string $propertyId): array;

    /**
     * Die Positionen, die eine beschlossene Massnahme bezahlen.
     *
     * @return list<CostItem>
     */
    public function forMeasure(string $reference): array;
}
