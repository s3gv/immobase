<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetClaim;
use App\Module\Billing\Domain\AssetDebt;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportDocument;
use App\Module\Billing\Domain\ReportedAssets;
use App\Module\Property\Contract\UnitBrief;

/**
 * Aus einem Entwurf werden Schreiben.
 *
 * Eingefroren wird, was von aussen kommt: die Forderungen, die Restschuld der
 * Darlehen und — am Bericht selbst — der Ruecklagenstand. Die erfassten
 * Positionen brauchen es nicht; sie stehen schon da, und nach der Herausgabe
 * fasst sie niemand mehr an.
 *
 * Dazu die Empfaenger: Name und Anschrift, wie sie am Tag der Herausgabe
 * galten. Wer umzieht, hat den Bericht unter der alten Anschrift bekommen.
 */
final class FreezeAssetReport
{
    private function __construct()
    {
    }

    /**
     * @param list<UnitBrief>                                      $units
     * @param array<string, array{label: string, address: string}> $recipients Kennung der Einheit auf ihren Empfaenger
     */
    public static function of(AssetReport $report, ReportedAssets $body, array $units, array $recipients): void
    {
        foreach ($body->claims as $claim) {
            new AssetClaim($report, $claim->unitNumber, $claim->amount, $claim->since);
        }

        foreach ($body->debts as $at => $debt) {
            new AssetDebt($report, $at + 1, $debt->label, $debt->outstanding);
        }

        foreach ($units as $unit) {
            $recipient = $recipients[$unit->id] ?? null;

            if (null !== $recipient) {
                new AssetReportDocument(
                    $report,
                    $unit->id,
                    $unit->number,
                    $unit->label,
                    $recipient['label'],
                    $recipient['address'],
                );
            }
        }
    }
}
