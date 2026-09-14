<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Finance\Contract\CostRecord;
use App\Shared\Money\Money;

/**
 * Die vier Betraege eines Budgetplans in der Form, in der verteilt wird.
 *
 * Derselbe Trick wie beim Wirtschaftsplan: aus einem Betrag wird ein
 * Kostensatz, und {@see ShareOutCosts} verteilt ihn wie jeden anderen. Ob die
 * Zahl ein Ist, ein Plan oder eine Sonderumlage ist, geht die Verteilung
 * nichts an — und es gibt keinen zweiten Verteilungsweg, der anders rundet.
 *
 * **Vier Betraege, vier Verteilungen.** Aus dem Anteil an der Massnahme
 * anteilig die Sonderumlage zu rechnen hiesse, Rundungsreste weiterzureichen,
 * bis die Summe der Schreiben nicht mehr der Beschluss ist. Jeder Betrag wird
 * darum fuer sich verteilt, jeder mit {@see Money::allocate()}, und jede Summe
 * stimmt auf den Cent.
 */
final class BudgetShares
{
    public const string NEED = 'need';
    public const string LEVY = 'levy';
    public const string SAVING = 'saving';
    public const string LOAN = 'loan';

    private function __construct()
    {
    }

    /**
     * @param array<string, Money> $amounts Kennung auf Betrag
     *
     * @return list<CostRecord>
     */
    public static function records(Budget $budget, array $amounts): array
    {
        $keyId = $budget->key()->id();

        if (null === $keyId) {
            return [];
        }

        $records = [];
        $at = 0;

        foreach ($amounts as $id => $amount) {
            if (!$amount->isZero()) {
                $records[] = self::recordOf($budget, $keyId, $id, $amount, ++$at);
            }
        }

        return $records;
    }

    private static function recordOf(Budget $budget, string $keyId, string $id, Money $amount, int $at): CostRecord
    {
        return new CostRecord(
            costYearId: $id,
            itemNumber: $at,
            costKindId: '',
            kindLabel: $budget->measure()->label(),
            apportionable: false,
            // Eine Massnahme teilt sich nicht tagesgenau: wer im Juli kauft,
            // uebernimmt sie ganz. Beschlossen wird sie an einem Tag, und an
            // dem gehoert die Einheit jemandem.
            splitsByDay: false,
            keyId: $keyId,
            keyLabel: $budget->key()->label(),
            keyKind: $budget->key()->kind(),
            total: $amount,
            inputTax: Money::zero(),
            measure: null,
            perUnit: [],
        );
    }
}
