<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DomainException;

/**
 * Was einem Wirtschaftsplan zur Freigabe fehlt.
 *
 * Eine fehlende Angabe wird nicht zu null: sie verteilte den Anteil einer
 * Einheit still auf die Nachbarn, und zwar fuer ein ganzes Jahr.
 */
final class PlanIsIncomplete extends DomainException
{
    public static function figuresAreMissing(): self
    {
        return new self('billing.plan.error.incomplete');
    }

    public static function thereIsNothingToSend(): self
    {
        return new self('billing.plan.error.nothing');
    }
}
