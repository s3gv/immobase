<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Was an einem Darlehen unterwegs passiert ist.
 *
 * Zwei Dinge, und beide veraendern den Tilgungsplan ab ihrem Tag:
 *
 * * Eine **Sondertilgung** mindert die Restschuld sofort. Der Plan wird
 *   dadurch kuerzer, nicht die Rate kleiner — so vereinbart es fast jeder
 *   Vertrag.
 * * Ein **neuer Zins** gilt ab dem Tag der Anschlussvereinbarung. Die Rate
 *   bleibt, das Verhaeltnis von Zins und Tilgung verschiebt sich.
 *
 * Die **Abloesung** ist keine dritte Art: sie ist eine Sondertilgung ueber
 * den Rest. Eine eigene Art dafuer waere eine zweite Schreibweise fuer
 * denselben Vorgang.
 */
enum LoanEventKind: string
{
    case ExtraPayment = 'extra_payment';
    case NewRate = 'new_rate';

    public function labelKey(): string
    {
        return 'finance.loan.event.'.$this->value;
    }

    public function needsAnAmount(): bool
    {
        return self::ExtraPayment === $this;
    }
}
