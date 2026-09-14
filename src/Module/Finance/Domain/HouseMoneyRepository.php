<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Zugriff auf die Hausgeld-Vorauszahlungen.
 */
interface HouseMoneyRepository
{
    public function save(HouseMoney $step): void;

    /**
     * Mehrere Stufen auf einmal — ein Flush fuer alle.
     *
     * Wer einen Wirtschaftsplan beschliesst, legt zwoelf Stufen an, nicht
     * eine. Zwoelf einzelne Fluesche waeren zwoelf Gelegenheiten, auf halbem
     * Weg liegenzubleiben.
     *
     * @param list<HouseMoney> $steps
     */
    public function saveAll(array $steps): void;

    public function remove(HouseMoney $step): void;

    public function byId(string $id): ?HouseMoney;

    /**
     * Die Stufen dieser Einheiten.
     *
     * Gefragt wird fuer eine ganze Seite auf einmal, nicht je Zeile.
     *
     * @param list<string> $unitIds
     *
     * @return array<string, AdvanceSchedule> Kennung der Einheit auf ihre Staffel
     */
    public function forUnits(array $unitIds): array;
}
