<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

use RuntimeException;

/**
 * Mit diesen Angaben geht kein Tilgungsplan auf.
 *
 * Steht in Shared wie {@see RepaymentPlan} selbst: geplant wird ein Darlehen
 * im Budgetplan, gefuehrt wird es in den Finanzen, und beide rechnen mit
 * derselben Klasse. Die Meldung traegt darum keinen Modulnamen.
 *
 * Eine Rate, die den Zins nicht deckt, tilgt nichts: die Restschuld waechst,
 * und der Plan endet nie. Eine Laufzeit von null Monaten ist keine Laufzeit.
 * Beides sind Eingaben, und beide bekommen eine Absage, die man versteht.
 */
final class LoanDoesNotAmortise extends RuntimeException
{
    public static function theRateIsTooSmall(): self
    {
        return new self('money.repayment.rate_too_small');
    }

    public static function theTermIsImpossible(): self
    {
        return new self('money.repayment.term_impossible');
    }
}
