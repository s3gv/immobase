<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Wer die Kosten traegt.
 *
 * Bei der Erhaltung ist die Frage nicht gestellt: alle. Bei einer baulichen
 * Veraenderung beantwortet sie § 21 WEG, und die Antwort haengt davon ab, wie
 * beschlossen wurde — siehe {@see \App\Module\Billing\Application\WhoBearsTheCosts}.
 *
 * Der Grund, warum das hier steht und nicht nur gerechnet wird: auf dem
 * Schreiben muss stehen, **warum** jemand zahlt. „Nach § 21 Abs. 3 WEG tragen
 * die Kosten die zustimmenden Eigentuemer" ist eine Auskunft; eine Zahl ohne
 * sie ist eine Zumutung.
 */
enum CostBearing: string
{
    case Everyone = 'everyone';
    case QualifiedMajority = 'qualified_majority';
    case Amortising = 'amortising';
    case OnlyThoseWhoAgreed = 'only_agreed';
    case OnlyWhoAsked = 'only_asked';

    public function labelKey(): string
    {
        return 'billing.budget.bearing.'.$this->value;
    }

    /** Der Satz fuer das Schreiben — mit der Norm, auf der er steht. */
    public function reasonKey(): string
    {
        return 'billing.budget.bearing_reason.'.$this->value;
    }

    /** Zahlen alle Einheiten — oder nur ein Teil? */
    public function isEveryone(): bool
    {
        return self::OnlyThoseWhoAgreed !== $this && self::OnlyWhoAsked !== $this;
    }
}
