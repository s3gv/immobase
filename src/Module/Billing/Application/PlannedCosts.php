<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Finance\Contract\CostDirectory;
use App\Module\Finance\Contract\CostRecord;
use App\Shared\Money\Money;

/**
 * Die Planzeilen in der Form, in der {@see ShareOutCosts} rechnet.
 *
 * Der Wirtschaftsplan verteilt mit demselben Werkzeug wie die Abrechnung. Das
 * ist der ganze Trick dieser Klasse: sie macht aus einer Planposition einen
 * Kostensatz, und ob der Betrag ein Ist oder ein Plan ist, geht die Verteilung
 * nichts an. Zwei Verteilungswege wuerden irgendwann verschieden runden, und
 * dann waere die Abrechnung ueber einen Plan nicht mehr die Abrechnung ueber
 * diesen Plan.
 *
 * **Erfasste Schluessel greifen auf das Vorjahr zurueck.** Fuer ein kuenftiges
 * Jahr gibt es keinen Verbrauch — den gibt es erst, wenn es vorbei ist. Also
 * wird nach dem Verbrauch des Vorjahres verteilt, und das steht auch so auf
 * dem Blatt ({@see ComposePlan}). Fehlt der, fehlt eine Angabe; erfunden wird
 * keine.
 */
final readonly class PlannedCosts
{
    public function __construct(private CostDirectory $costs)
    {
    }

    /**
     * Die Zeilen, die etwas zu verteilen haben.
     *
     * Ohne Schluessel und ohne Betrag wird nicht verteilt: eine Zeile mit
     * Planwert null hat nichts zu verteilen, und ein erfasster Schluessel
     * meldete dafuer trotzdem jede fehlende Messung.
     *
     * @return list<CostRecord>
     */
    public function of(Plan $plan): array
    {
        $before = $this->byId($plan);
        $records = [];

        foreach ($plan->positions() as $position) {
            $keyId = $position->key()->id();

            if (null === $keyId || $position->planned()->isZero()) {
                continue;
            }

            $records[] = self::recordOf($position, $keyId, $before[$position->previousSourceId() ?? ''] ?? null);
        }

        return $records;
    }

    private static function recordOf(PlanPosition $position, string $keyId, ?CostRecord $before): CostRecord
    {
        return new CostRecord(
            // Die Kennung der Zeile ist die der Position: die Verteilung
            // gibt ihre Ergebnisse darunter zurueck, und hier gibt es keine
            // Jahreswerte, unter denen sie sonst stuenden.
            costYearId: $position->id(),
            itemNumber: $position->ordering(),
            costKindId: $position->costKindId() ?? '',
            kindLabel: $position->costKindLabel(),
            apportionable: $position->isApportionable(),
            // Ein Plan gilt fuer das ganze Jahr; wer unterjaehrig kauft,
            // uebernimmt den laufenden Vorschuss und bekommt keinen halben
            // Plan. Darum nie tagesgenau.
            splitsByDay: false,
            keyId: $keyId,
            keyLabel: $position->key()->label(),
            keyKind: $position->key()->kind(),
            total: $position->planned(),
            inputTax: Money::zero(),
            measure: $before?->measure,
            perUnit: $before->perUnit ?? [],
        );
    }

    /**
     * Die Jahreswerte des Vorjahres, nach ihrer Kennung.
     *
     * @return array<string, CostRecord>
     */
    private function byId(Plan $plan): array
    {
        $found = [];

        foreach ($this->costs->forYear($plan->propertyId(), $plan->period()->year() - 1) as $cost) {
            $found[$cost->costYearId] = $cost;
        }

        return $found;
    }
}
