<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Die Nummer, unter der ein Einzelwirtschaftsplan angesprochen wird.
 *
 * `WP-<Objektnummer>/<Einheitennummer>-<Jahr>-<Plannummer>-<Iteration>`.
 *
 * An einer Stelle, weil sie an dreien gebraucht wird: am eingefrorenen
 * Dokument, auf dem Blatt und als Dateiname im Archiv. Dreimal
 * zusammengesetzt waeren drei Gelegenheiten, dass eine davon anders aussieht
 * als die, die der Empfaenger am Telefon vorliest.
 */
final class PlanReference
{
    private function __construct()
    {
    }

    public static function of(Plan $plan, int $unitNumber): Reference
    {
        return new Reference(
            'WP',
            $plan->propertyNumber(),
            $unitNumber,
            $plan->period()->year(),
            $plan->edition()->number(),
            $plan->edition()->iteration(),
        );
    }
}
