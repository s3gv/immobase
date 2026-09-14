<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetClaim;
use App\Module\Billing\Domain\AssetDebt;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\ReportedAssets;
use App\Module\Billing\Domain\ReportedClaim;
use App\Module\Billing\Domain\ReportedDebt;
use App\Module\Billing\Domain\ReportedReserve;
use App\Module\Finance\Contract\ClaimDirectory;
use App\Module\Finance\Contract\LoanDirectory;
use App\Module\Finance\Contract\OpenClaim;
use App\Module\Finance\Contract\OpenLoan;
use App\Module\Finance\Contract\ReserveDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;

/**
 * Den Bericht zusammenstellen.
 *
 * Ein Entwurf wird gerechnet, ein herausgegebener gelesen — und beide Male
 * kommt dieselbe Gestalt heraus. Die Vorschau zeigt damit, was herausgehen
 * wird, und das zugestellte Schreiben zeigt, was herausging.
 *
 * Der Schnitt liegt genau an der Herausgabe, und das ist der ganze Trick an
 * der Unumkehrbarkeit: danach wird nichts mehr gerechnet, also kann die
 * naechste Ruecklagenbuchung auch nichts mehr aendern.
 */
final readonly class ComposeAssetReport
{
    public function __construct(
        private ReserveDirectory $reserve,
        private ClaimDirectory $claims,
        private LoanDirectory $loans,
        private UnitDirectory $units,
    ) {
    }

    public function of(AssetReport $report): ReportedAssets
    {
        if ($report->release()->isDraft()) {
            return new ReportedAssets(
                $this->standingOf($report),
                $report->items(),
                $this->openOf($report),
                $this->owedOf($report),
            );
        }

        return new ReportedAssets(
            $report->reserve(),
            $report->items(),
            array_map(static fn (AssetClaim $claim): ReportedClaim => $claim->asReported(), $report->claims()),
            array_map(static fn (AssetDebt $debt): ReportedDebt => $debt->asReported(), $report->debts()),
        );
    }

    /**
     * Die Einheiten des Objekts — auch fuer die Empfaenger.
     *
     * @return list<UnitBrief>
     */
    public function unitsOf(AssetReport $report): array
    {
        return $this->units->ofProperty($report->propertyNumber());
    }

    /**
     * Die Darlehen mit ihrer Restschuld am Stichtag.
     *
     * **Gerechnet und nicht erfasst.** Die Restschuld steht im Tilgungsplan
     * der Finanzen; sie hier eintippen zu lassen hiesse, sie einmal im Jahr
     * abzuschreiben — und danach zwei Zahlen zu haben.
     *
     * @return list<ReportedDebt>
     */
    private function owedOf(AssetReport $report): array
    {
        return array_map(
            static fn (OpenLoan $loan): ReportedDebt => new ReportedDebt($loan->label, $loan->outstanding),
            $this->loans->outstandingAt($report->propertyId(), $report->asOf()),
        );
    }

    private function standingOf(AssetReport $report): ReportedReserve
    {
        return ReportedReserve::of($this->reserve->standingAt(
            $report->propertyId(),
            $report->period()->from(),
            $report->asOf(),
        ));
    }

    /**
     * Die offenen Hausgelder zum Stichtag, mit der Nummer der Einheit.
     *
     * @return list<ReportedClaim>
     */
    private function openOf(AssetReport $report): array
    {
        $units = $this->unitsOf($report);
        $numbers = [];

        foreach ($units as $unit) {
            $numbers[$unit->id] = $unit->number;
        }

        $open = $this->claims->openAdvances(array_keys($numbers), $report->asOf());

        return array_map(
            static fn (OpenClaim $claim): ReportedClaim => new ReportedClaim(
                $numbers[$claim->unitId] ?? 0,
                $claim->open,
                $claim->since,
            ),
            $open,
        );
    }
}
