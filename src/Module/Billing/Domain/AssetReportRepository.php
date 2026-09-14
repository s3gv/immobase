<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Ui\Page;

interface AssetReportRepository
{
    /**
     * @throws AssetReportIterationIsTaken wenn dieselbe Fassung schon existiert
     */
    public function save(AssetReport $report): void;

    /** Nur Entwuerfe — ein herausgegebener Bericht ist zugestellt. */
    public function remove(AssetReport $report): void;

    public function byId(string $id): ?AssetReport;

    /** Die naechste sichtbare Nummer, aus der Sequenz. */
    public function nextNumber(): int;

    public function countMatching(AssetReportFilter $filter): int;

    /**
     * @return list<AssetReport> die neuesten zuerst
     */
    public function matching(AssetReportFilter $filter, Page $page): array;

    /**
     * Die Berichtsjahre, zu denen es Berichte gibt — die juengsten zuerst.
     *
     * @return list<int>
     */
    public function years(): array;

    /**
     * Alle Fassungen eines Berichts, die erste zuerst.
     *
     * @return list<AssetReport>
     */
    public function iterationsOf(int $number): array;

    /**
     * Der zuletzt herausgegebene Bericht eines Objekts vor diesem Jahr.
     *
     * Aus ihm kommen die Bezeichnungen des naechsten — die Betraege nicht.
     */
    public function lastReleasedBefore(string $propertyId, int $fiscalYear): ?AssetReport;
}
