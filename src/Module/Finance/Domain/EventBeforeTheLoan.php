<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Vor der ersten Rate gibt es keinen Plan, den ein Vorgang aendern koennte.
 *
 * {@see \App\Shared\Money\PlanChange} zaehlt in Monaten ab eins: „nach der
 * zwoelften Rate". Ein Tag vor der ersten Rate ergibt Monat null, und den
 * gibt es nicht — die Rechnung liesse den Vorgang stumm fallen, waehrend er
 * im Verlauf steht und etwas anderes behauptet.
 *
 * Die Absage und nicht das Vorziehen: ein Zins, der schon vor der ersten Rate
 * gilt, **ist** der vereinbarte Zins, und den aendert man in den Konditionen.
 * Zwei Wege fuer dieselbe Sache waeren zwei Wahrheiten.
 */
final class EventBeforeTheLoan extends RuntimeException
{
    public static function of(): self
    {
        return new self('finance.error.loan_event_too_early');
    }
}
