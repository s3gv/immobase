<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Die Nummer, unter der ein Vermoegensbericht angesprochen wird.
 *
 * `VB-<Objektnummer>/<Einheitennummer>-<Jahr>-<Berichtsnummer>-<Fassung>` —
 * dieselbe Form wie bei Abrechnung und Wirtschaftsplan, nur mit einem anderen
 * Kuerzel. Wer drei Schreiben auf dem Tisch hat, soll sie gleich lesen
 * koennen.
 */
final class AssetReportReference
{
    private function __construct()
    {
    }

    public static function of(AssetReport $report, int $unitNumber): Reference
    {
        return new Reference(
            'VB',
            $report->propertyNumber(),
            $unitNumber,
            $report->period()->year(),
            $report->edition()->number(),
            $report->edition()->iteration(),
        );
    }
}
