<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Es fehlt etwas, ohne das der Budgetplan nicht herausgehen darf.
 *
 * Die **Deckungsluecke** ist dabei der wichtigste Fall: eine Finanzierung, die
 * den Bedarf nicht deckt, ist keine Finanzierung. Beschlossen wuerde eine
 * Massnahme, fuer die das Geld nicht reicht — und die Luecke faellt erst auf,
 * wenn die Rechnung kommt.
 */
final class BudgetIsIncomplete extends RuntimeException
{
    public static function theFundingDoesNotCover(): self
    {
        return new self('billing.budget.error.gap');
    }

    public static function thereIsNothingToPlan(): self
    {
        return new self('billing.budget.error.no_positions');
    }

    public static function noKeyWasChosen(): self
    {
        return new self('billing.budget.error.no_key');
    }

    public static function thereIsNoOneToSendTo(): self
    {
        return new self('billing.budget.error.no_recipients');
    }

    /**
     * Ohne gezaehlte Stimmen steht bei einer baulichen Veraenderung nicht
     * fest, wer traegt.
     *
     * Bis dahin ist der Verteilerkreis eine Annahme — die darf auf einer
     * Vorlage stehen und nicht auf einem Beschluss. Sonst behauptete das
     * Schreiben eine Mehrheit, die niemand gezaehlt hat.
     */
    public static function theVoteWasNotCounted(): self
    {
        return new self('billing.budget.error.no_vote');
    }

    /** Ohne Zustimmende gibt es niemanden, der traegt. */
    public static function noOneAgreed(): self
    {
        return new self('billing.budget.error.no_approvals');
    }
}
