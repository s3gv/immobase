<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetReportFilter;
use App\Module\Billing\Domain\AssetReportRepository;
use DateTimeImmutable;

/**
 * Was auf der Uebersicht ueber die Vermoegensberichte steht.
 *
 * Zwei Zahlen und ein Jahr: was angefangen ist, was heraus ist, und worueber
 * gerade zu berichten waere.
 */
final readonly class SurveyAssetReports
{
    public function __construct(private AssetReportRepository $reports)
    {
    }

    /**
     * @return array{drafts: int, released: int, year: int}
     */
    public function overview(): array
    {
        $all = $this->reports->countMatching(AssetReportFilter::none());
        $drafts = $this->reports->countMatching(AssetReportFilter::draftsOnly());

        return [
            'drafts' => $drafts,
            'released' => $all - $drafts,
            'year' => self::yearToReport(),
        ];
    }

    /**
     * Das Jahr, ueber das jetzt zu berichten ist.
     *
     * Immer das vergangene: § 28 Abs. 4 WEG verlangt den Bericht „nach Ablauf
     * des Kalenderjahres". Ueber das laufende gibt es keinen — der Stichtag
     * steht noch bevor.
     */
    public static function yearToReport(): int
    {
        return (int) (new DateTimeImmutable('today'))->format('Y') - 1;
    }
}
