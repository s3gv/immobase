<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use Symfony\Component\HttpFoundation\Request;

/**
 * Die Zeilen der Vermoegensaufstellung, gelesen und geprueft.
 *
 * Jede Zeile wird ueber ihre eigene Kennung angesprochen. Ueber die Stelle in
 * der Liste ginge es auch — bis jemand eine Zeile entfernt und alle darunter
 * eine andere Zahl bekommen.
 *
 * **Ein leeres Feld bleibt leer.** Anders als beim Wirtschaftsplan, wo ein
 * leerer Betrag „nichts geplant" heisst, ist er hier eine fehlende Angabe:
 * ein Konto ohne Stand ist keine Auskunft. Er wird darum zu null statt zu
 * einer Null — und haelt die Herausgabe auf.
 */
final class AssetItemRows
{
    private function __construct()
    {
    }

    /**
     * @return array{rows: array<string, array{label: string, amount: \App\Shared\Money\Money|null, earmarked: bool, note: string}>, errors: array<string, string>}
     */
    public static function from(Request $request, string $field): array
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
                    'amount' => MoneyInput::orNull(self::text($values, 'amount')),
                    'earmarked' => '' !== self::text($values, 'earmarked'),
                    'note' => self::text($values, 'note'),
                ];
            } catch (UnreadableAmount) {
                $errors[$field.'.'.$id] = 'billing.report.error.amount';
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
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
