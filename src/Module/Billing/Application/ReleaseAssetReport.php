<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportIsIncomplete;
use App\Module\Billing\Domain\AssetReportIsReleased;
use App\Module\Billing\Domain\AssetReportRepository;
use DateTimeImmutable;

/**
 * Den Bericht herausgeben.
 *
 * Es gibt nichts zu beschliessen — § 28 Abs. 4 WEG verlangt eine Auskunft und
 * keine Entscheidung. Herausgeben heisst darum nur zweierlei: die Schreiben
 * entstehen, und ab hier aendert sich nichts mehr daran.
 *
 * Das Zweite ist das Wichtigere. Ein Bericht, der seinen Ruecklagenstand
 * weiter aus den laufenden Buchungen holte, wuerde sich mit der naechsten
 * Entnahme rueckwirkend aendern — auf einem Blatt, das jemand schon in der
 * Hand haelt. Darum wird alles, was von aussen kommt, in diesem Moment
 * festgeschrieben.
 */
final readonly class ReleaseAssetReport
{
    public function __construct(
        private AssetReportRepository $reports,
        private ComposeAssetReport $compose,
        private OwnersOnADay $recipients,
    ) {
    }

    /**
     * @throws AssetReportIsReleased
     * @throws AssetReportIsIncomplete
     */
    public function release(AssetReport $report, DateTimeImmutable $on): void
    {
        if (!$report->release()->isDraft()) {
            throw AssetReportIsReleased::already();
        }

        if ([] !== AssetReportGaps::of($report)) {
            throw AssetReportIsIncomplete::amountsAreMissing();
        }

        $units = $this->compose->unitsOf($report);
        $recipients = $this->recipients->on($units, $report->asOf());

        if ([] === $recipients) {
            throw AssetReportIsIncomplete::thereIsNoOneToSendTo();
        }

        $body = $this->compose->of($report);
        FreezeAssetReport::of($report, $body, $units, $recipients);
        $report->releaseOn($on, $body->reserve);
        $this->reports->save($report);
    }
}
