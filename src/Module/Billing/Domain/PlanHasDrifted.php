<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DomainException;

/**
 * Die Grundlage hat sich geaendert, seit die Vorlage herausging.
 *
 * Beschlossen wird, was vorlag — aber nicht versehentlich. Wer hier
 * freigibt, schreibt die vorgelegten Betraege fuer ein ganzes Jahr in die
 * Hausgeldstaffel, obwohl heute andere herauskaemen. Das darf man; man muss
 * es nur wissen.
 */
final class PlanHasDrifted extends DomainException
{
    public static function sinceItWasProposed(): self
    {
        return new self('billing.plan.error.drifted');
    }
}
