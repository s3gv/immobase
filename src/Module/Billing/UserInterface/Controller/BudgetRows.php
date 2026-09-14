<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use Symfony\Component\HttpFoundation\Request;

/**
 * Die Positionen des Bedarfs, gelesen und geprueft.
 *
 * Jede Zeile ueber ihre eigene Kennung: wer eine entfernt, verschoebe sonst
 * alle darunter. Ein unlesbarer Betrag ist ein Fehler an **seiner** Zeile und
 * nicht am Formular.
 */
final class BudgetRows
{
    private function __construct()
    {
    }

    /**
     * @return array{rows: array<string, array{label: string, year: int, amount: Money, note: string}>, errors: array<string, string>}
     */
    public static function from(Request $request, string $field, int $fallbackYear): array
    {
        $rows = [];
        $errors = [];

        foreach ($request->request->all($field) as $id => $values) {
            if (!\is_string($id) || !\is_array($values)) {
                continue;
            }

            try {
                $rows[$id] = [
                    'label' => self::text($values, 'label'),
                    'year' => self::yearOf(self::text($values, 'year'), $fallbackYear),
                    'amount' => MoneyInput::orNull(self::text($values, 'amount')) ?? Money::zero(),
                    'note' => self::text($values, 'note'),
                ];
            } catch (UnreadableAmount) {
                $errors[$field.'.'.$id] = 'billing.budget.error.amount';
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    private static function yearOf(string $value, int $fallback): int
    {
        return ctype_digit($value) && 4 === \strlen($value) ? (int) $value : $fallback;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function text(array $values, string $name): string
    {
        $value = $values[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }
}
