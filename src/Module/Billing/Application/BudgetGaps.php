<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\MissingFigure;
use App\Module\Property\Contract\UnitBrief;
use App\Shared\Money\Money;

/**
 * Was einen Budgetplan davon abhaelt, beschlossen zu werden.
 *
 * Vier Luecken, und jede waere still:
 *
 * * **Keine Position.** Eine Massnahme ohne Bedarf ist ein Beschluss ueber
 *   nichts.
 * * **Kein Verteilerschluessel.** Der Betrag haette kein Ziel.
 * * **Eine Deckungsluecke.** Die Finanzierung traegt den Bedarf nicht — das
 *   faellt sonst erst auf, wenn die Rechnung kommt.
 * * **Niemand, der traegt.** Bei einer baulichen Veraenderung ohne
 *   qualifizierte Mehrheit tragen nur die Zustimmenden; hat niemand
 *   zugestimmt, gibt es niemanden, auf den zu verteilen waere.
 */
final class BudgetGaps
{
    private function __construct()
    {
    }

    /**
     * @param list<UnitBrief>                                      $bearers
     * @param array<string, array{label: string, address: string}> $whom
     *
     * @return list<MissingFigure>
     */
    public static function of(Budget $budget, Money $gap, array $bearers, array $whom): array
    {
        $missing = self::ofThePlan($budget, $gap);

        if ([] === $bearers) {
            $missing[] = self::figure($budget, 'billing.budget.missing.bearers');
        }

        foreach ($bearers as $unit) {
            if (!isset($whom[$unit->id])) {
                $missing[] = new MissingFigure($unit->id, $unit->label, '', 'billing.budget.missing.owner');
            }
        }

        return $missing;
    }

    /**
     * @return list<MissingFigure>
     */
    private static function ofThePlan(Budget $budget, Money $gap): array
    {
        $missing = [];

        if ([] === $budget->positions()) {
            $missing[] = self::figure($budget, 'billing.budget.missing.positions');
        }

        if (!$budget->key()->isChosen()) {
            $missing[] = self::figure($budget, 'billing.budget.missing.key');
        }

        if (!$gap->isZero()) {
            $missing[] = self::figure($budget, 'billing.budget.missing.gap');
        }

        return $missing;
    }

    private static function figure(Budget $budget, string $whatKey): MissingFigure
    {
        return new MissingFigure('', (string) $budget->propertyNumber(), $budget->measure()->label(), $whatKey);
    }
}
