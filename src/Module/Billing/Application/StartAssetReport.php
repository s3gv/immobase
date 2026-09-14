<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\ReportYearIsNotOver;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use DateTimeImmutable;

/**
 * Einen Vermoegensbericht anlegen — und gleich vorbelegen.
 *
 * **Nur WEG-Objekte.** Ohne Gemeinschaft gibt es kein Gemeinschaftsvermoegen,
 * ueber das zu berichten waere. Die Oberflaeche bietet auch nur solche an —
 * aber ein abgeschicktes Formular ist Eingabe und keine Zusicherung.
 *
 * **Nur abgelaufene Jahre.** Der Bericht spricht ueber einen Stichtag; liegt
 * der in der Zukunft, stuende darin, was am Jahresende auf dem Konto sein
 * wird.
 */
final readonly class StartAssetReport
{
    public function __construct(
        private AssetReportRepository $reports,
        private PropertyDirectory $properties,
        private StatementPeriod $period,
        private AssetsFromLastYear $lastYear,
    ) {
    }

    /**
     * @return AssetReport|null null, wenn Objekt oder Jahr nicht taugen
     *
     * @throws ReportYearIsNotOver wenn der Stichtag noch bevorsteht
     */
    public function forProperty(?int $propertyNumber, ?int $fiscalYear, string $label): ?AssetReport
    {
        $property = null === $propertyNumber ? null : $this->withNumber($propertyNumber);

        if (null === $property || null === $fiscalYear || !$property->managesWeg) {
            return null;
        }

        $period = $this->period->of($property->id, $fiscalYear);

        if ($period->to() >= new DateTimeImmutable('today')) {
            throw ReportYearIsNotOver::itIsStillRunning();
        }

        $report = new AssetReport(
            $this->reports->nextNumber(),
            $property->id,
            $property->number,
            $period,
        );
        $report->describe($label);
        $this->reports->save($report);

        $this->lastYear->fill($report);
        $this->reports->save($report);

        return $report;
    }

    private function withNumber(int $number): ?PropertyBrief
    {
        foreach ($this->properties->all() as $property) {
            if ($property->number === $number) {
                return $property;
            }
        }

        return null;
    }
}
