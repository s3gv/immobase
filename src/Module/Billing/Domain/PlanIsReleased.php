<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DomainException;

/**
 * Ein freigegebener Wirtschaftsplan aendert sich nicht mehr.
 *
 * Auf ihm stehen die Vorschuesse, die die Eigentuemer zahlen. Was daran falsch
 * ist, wird korrigiert und nicht ueberschrieben.
 */
final class PlanIsReleased extends DomainException
{
    public static function already(): self
    {
        return new self('billing.plan.error.released');
    }
}
