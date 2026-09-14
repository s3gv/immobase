<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Was an einem Budgetplan auf dem Blatt steht — als eine Zeichenkette.
 *
 * Sie wird nie gespeichert und nie angezeigt und beantwortet genau eine Frage:
 * **hat sich seit vorhin etwas geaendert, das der Empfaenger sieht?** Wer eine
 * Beschlussvorlage herausgegeben hat und danach eine Zahl anfasst, hat ein
 * anderes Dokument als das, das den Eigentuemern vorlag.
 *
 * **Das Abstimmungsergebnis zaehlt nicht dazu.** Es kommt nach der Vorlage —
 * es einzutragen darf sie nicht entwerten. Das ist der Unterschied zum
 * Wirtschaftsplan: dort beschliesst die Versammlung ueber Zahlen, hier
 * ausserdem darueber, wer sie traegt.
 */
final class BudgetContents
{
    private function __construct()
    {
    }

    public static function of(Budget $budget): string
    {
        $measure = $budget->measure();
        $funding = $budget->funding();
        $parts = [
            $measure->label(),
            $measure->kind()->value,
            (string) $measure->firstYear(),
            (string) ($measure->amortisesIn() ?? 0),
            $budget->key()->id() ?? '',
            self::fundingOf($funding),
        ];

        foreach ($budget->positions() as $position) {
            $parts[] = self::rowOf($position);
        }

        return implode('|', $parts);
    }

    private static function fundingOf(Funding $funding): string
    {
        return implode('~', [
            (string) $funding->reserve()->cents(),
            (string) $funding->levy()->cents(),
            $funding->levyDueOn()?->format('Y-m-d') ?? '',
            (string) $funding->levyParts(),
            $funding->levyInterval()->value,
            $funding->levyPurpose()->value,
            (string) $funding->saving()->cents(),
            (string) $funding->savingYears(),
            (string) $funding->savingFrom(),
            (string) $funding->loan()->cents(),
            (string) $funding->loanRateBps(),
            (string) ($funding->loanPayment()?->cents() ?? 0),
            (string) ($funding->loanMonths() ?? 0),
        ]);
    }

    private static function rowOf(BudgetPosition $position): string
    {
        return implode('~', [
            $position->id(),
            $position->label(),
            (string) $position->year(),
            (string) $position->amount()->cents(),
            $position->note(),
        ]);
    }
}
