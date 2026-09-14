<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Je Vorgang die juengste Fassung.
 *
 * Eine Berichtigung **ersetzt** ihre Vorgaengerin, sie kommt nicht dazu. Wer
 * beide zaehlt, zaehlt dasselbe Dach zweimal — und das ist keine Frage der
 * Darstellung, sondern eine falsche Summe.
 *
 * Steht hier und nicht dreimal verteilt: die Uebersicht, die Zufuehrung aus
 * beschlossenen Budgetplaenen und die Liste der Massnahmen beantworten
 * dieselbe Frage.
 */
final class LatestEditions
{
    private function __construct()
    {
    }

    /**
     * @param list<Budget> $budgets
     *
     * @return list<Budget>
     */
    public static function of(array $budgets): array
    {
        $latest = [];

        foreach ($budgets as $budget) {
            $known = $latest[$budget->edition()->number()] ?? null;

            if (null === $known || $known->edition()->iteration() < $budget->edition()->iteration()) {
                $latest[$budget->edition()->number()] = $budget;
            }
        }

        return array_values($latest);
    }
}
