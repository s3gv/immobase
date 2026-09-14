<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\MissingFigure;
use App\Module\Billing\Domain\Plan;
use App\Module\Property\Contract\UnitBrief;

/**
 * Was einem Wirtschaftsplan fehlt, bevor er hinausgehen kann.
 *
 * Zwei Luecken, die {@see Weights} nicht
 * kennen kann, weil sie schon vor der Verteilung liegen:
 *
 * * **Eine Zeile ohne Verteilerschluessel.** Ihr Betrag hat kein Ziel. Sie
 *   still zu ueberspringen hiesse, Geld verschwinden zu lassen — der
 *   Gesamtplan stuende hoeher da als die Summe der Einzelplaene.
 * * **Eine Einheit ohne eingetragenen Eigentuemer.** Sie bekaeme kein
 *   Schreiben, traegt aber ihren Anteil. Die Gemeinschaft plante damit
 *   Einnahmen, die niemand angefordert hat.
 */
final class PlanGaps
{
    private function __construct()
    {
    }

    /**
     * @param list<UnitBrief>                                      $units
     * @param array<string, array{label: string, address: string}> $whom
     *
     * @return list<MissingFigure>
     */
    public static function of(Plan $plan, array $units, array $whom): array
    {
        $missing = [];

        foreach ($plan->positions() as $position) {
            if (!$position->key()->isChosen() && !$position->planned()->isZero()) {
                $missing[] = new MissingFigure(
                    '',
                    (string) $plan->propertyNumber(),
                    $position->costKindLabel(),
                    'billing.plan.missing.key',
                );
            }
        }

        foreach ($units as $unit) {
            if (!isset($whom[$unit->id])) {
                $missing[] = new MissingFigure($unit->id, $unit->label, '', 'billing.plan.missing.owner');
            }
        }

        return $missing;
    }
}
