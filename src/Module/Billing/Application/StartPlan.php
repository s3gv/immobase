<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;

/**
 * Einen Wirtschaftsplan anlegen — und gleich vorbelegen.
 *
 * Wer im naechsten Schritt ankommt, findet die Kosten des Vorjahres und die
 * Ruecklagenzeile schon da: ein leeres Formular waere die Aufforderung,
 * fuenfzehn Zahlen aus dem Kopf einzutragen.
 *
 * **Nur WEG-Objekte.** Ohne Miteigentum gibt es keine Vorschuesse, ueber die
 * eine Versammlung beschliessen koennte. Die Oberflaeche bietet auch nur
 * solche an — aber ein abgeschicktes Formular ist Eingabe und keine
 * Zusicherung.
 */
final readonly class StartPlan
{
    public function __construct(
        private PlanRepository $plans,
        private PropertyDirectory $properties,
        private StatementPeriod $period,
        private PlanFromLastYear $lastYear,
    ) {
    }

    /**
     * @return Plan|null null, wenn Objekt oder Planjahr nicht taugen
     */
    public function forProperty(?int $propertyNumber, ?int $fiscalYear, string $label): ?Plan
    {
        $property = null === $propertyNumber ? null : $this->withNumber($propertyNumber);

        if (null === $property || null === $fiscalYear || !$property->managesWeg) {
            return null;
        }

        $plan = new Plan(
            $this->plans->nextNumber(),
            $property->id,
            $property->number,
            $this->period->of($property->id, $fiscalYear),
        );
        $plan->describe($label);
        $this->plans->save($plan);

        $this->lastYear->fill($plan);
        $this->plans->save($plan);

        return $plan;
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
