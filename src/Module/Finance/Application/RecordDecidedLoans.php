<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\DecidedLoan;
use App\Module\Finance\Contract\DecidedLoans;
use App\Module\Finance\Domain\LoanRepository;
use App\Module\Finance\Domain\LoanTerms;

/**
 * Den beschlossenen Finanzierungsweg als Darlehen fuehren.
 *
 * Angelegt wird es mit dem, was der Beschluss nennt, und mit dem Tag der
 * Freigabe als erster Rate: der Vertrag wird danach unterschrieben, und die
 * Bank nennt ihren Termin selbst. Wer ihn kennt, traegt ihn in den Finanzen
 * nach — dort ist das Darlehen zu Hause.
 */
final readonly class RecordDecidedLoans implements DecidedLoans
{
    public function __construct(
        private LoanRepository $loans,
        private SaveLoan $save,
    ) {
    }

    public function decided(DecidedLoan $decided): void
    {
        if (null !== $this->loans->byReference($decided->reference)) {
            return;
        }

        $loan = $this->save->forProperty($decided->propertyId, self::termsOf($decided));
        $loan->comesFrom($decided->reference);
        $this->save->describe($loan, $decided->label, '', '');
    }

    private static function termsOf(DecidedLoan $decided): LoanTerms
    {
        if (null !== $decided->payment) {
            return LoanTerms::withPayment($decided->amount, $decided->rateBps, $decided->startsOn, $decided->payment);
        }

        return LoanTerms::overMonths($decided->amount, $decided->rateBps, $decided->startsOn, $decided->months ?? 0);
    }
}
