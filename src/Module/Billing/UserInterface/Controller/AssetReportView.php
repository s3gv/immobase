<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\AssetReportGaps;
use App\Module\Billing\Application\ComposeAssetReport;
use App\Module\Billing\Application\OwnersOnADay;
use App\Module\Billing\Application\SurveyAssetReports;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitBrief;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was jeder Berichtsschritt anzuzeigen hat.
 *
 * Getrennt vom Controller, weil das Zusammentragen mehr Zeilen braucht als das
 * Entscheiden — und weil die Vorschau dieselbe Zusammenstellung benutzt wie die
 * Herausgabe.
 */
final readonly class AssetReportView
{
    /** So weit zurueck laesst sich ein Berichtsjahr waehlen. */
    private const int YEARS_BACK = 4;

    public function __construct(
        private AssetReportFlowPage $page,
        private ComposeAssetReport $compose,
        private OwnersOnADay $owners,
        private PropertyDirectory $properties,
        private BillingPage $trail,
    ) {
    }

    /**
     * Der erste Schritt, bevor es den Bericht gibt.
     *
     * Nur WEG-Objekte stehen zur Wahl: ohne Gemeinschaft gibt es kein
     * Gemeinschaftsvermoegen, ueber das zu berichten waere.
     *
     * @return array<string, mixed>
     */
    public function start(Request $request): array
    {
        return [
            ...$this->page->frame(null, AssetReportFlow::BASICS),
            'report' => null,
            'properties' => $this->weg(),
            'years' => self::years(),
            'defaultYear' => SurveyAssetReports::yearToReport(),
            'submitted' => $request->request->all(),
            'trail' => $this->trail->trail('billing.report.heading'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function of(AssetReport $report, string $step): array
    {
        $body = $this->compose->of($report);

        return [
            ...$this->page->frame($report, $step),
            'report' => $report,
            'properties' => $this->weg(),
            'years' => self::years(),
            'defaultYear' => SurveyAssetReports::yearToReport(),
            'submitted' => [],
            'body' => $body,
            'banks' => $body->of(AssetKind::Bank),
            'liabilities' => $body->of(AssetKind::Liability),
            'holdings' => $body->of(AssetKind::Holding),
            'missing' => AssetReportGaps::of($report),
            'recipients' => $this->recipientsOf($report),
            'trail' => $this->trail->trail('billing.report.heading'),
        ];
    }

    /**
     * Wer den Bericht bekaeme — Nummer, Bezeichnung, Empfaenger.
     *
     * Eine Einheit ohne Eigentuemer zum Stichtag steht mit leerem Empfaenger
     * da, statt zu fehlen: „hier bekommt niemand Post" ist die Auskunft, um
     * die es geht.
     *
     * @return list<array{unit: string, recipient: string}>
     */
    private function recipientsOf(AssetReport $report): array
    {
        $units = $this->compose->unitsOf($report);
        $found = $this->owners->on($units, $report->asOf());

        return array_map(
            static fn (UnitBrief $unit): array => [
                'unit' => $unit->number.' · '.$unit->label,
                'recipient' => $found[$unit->id]['label'] ?? '',
            ],
            $units,
        );
    }

    /**
     * Die Objekte, fuer die ein Vermoegensbericht ueberhaupt Sinn ergibt.
     *
     * @return list<PropertyBrief>
     */
    private function weg(): array
    {
        return array_values(array_filter(
            $this->properties->all(),
            static fn (PropertyBrief $property): bool => $property->managesWeg,
        ));
    }

    /**
     * Die Berichtsjahre zur Wahl, das spaeteste zuerst.
     *
     * Kein kuenftiges: § 28 Abs. 4 WEG verlangt den Bericht nach Ablauf des
     * Jahres, und ueber einen Stichtag, der noch bevorsteht, laesst sich
     * nichts sagen. Zurueck reicht die Liste, weil Berichte nachgetragen
     * werden.
     *
     * @return list<int>
     */
    private static function years(): array
    {
        $latest = SurveyAssetReports::yearToReport();

        return range($latest, $latest - self::YEARS_BACK);
    }
}
