<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Was die Massnahme kostet — und wann.
 *
 * Die Summe der Positionen ist der Bedarf. Ihre Verteilung ueber die Jahre ist
 * die zweite Auskunft, die eine Versammlung braucht: eine Massnahme ueber drei
 * Bauabschnitte braucht nicht dreimal so viel Geld auf einmal, sondern
 * dreimal Geld nacheinander.
 */
final class BudgetNeed
{
    private function __construct()
    {
    }

    public static function of(Budget $budget): Money
    {
        $sum = Money::zero();

        foreach ($budget->positions() as $position) {
            $sum = $sum->plus($position->amount());
        }

        return $sum;
    }

    /**
     * Der Bedarf je Jahr, das frueheste zuerst.
     *
     * @return array<int, Money>
     */
    public static function perYear(Budget $budget): array
    {
        $years = [];

        foreach ($budget->positions() as $position) {
            $known = $years[$position->year()] ?? Money::zero();
            $years[$position->year()] = $known->plus($position->amount());
        }

        ksort($years);

        return $years;
    }
}
