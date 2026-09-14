<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use RuntimeException;

/**
 * Ein Schreiben, ein Glaeubiger.
 *
 * Hausgeld schuldet der Eigentuemer der Gemeinschaft, die
 * Nebenkostenvorauszahlung schuldet der Mieter seinem Vermieter. Beides in
 * einem Brief waere eine Forderung aus zwei Haenden — und eine Zahlung darauf
 * wuesste nicht, wem sie gehoert.
 */
final class CreditorsDoNotMatch extends RuntimeException
{
    public static function inOneNotice(): self
    {
        return new self('dunning.error.creditors_differ');
    }
}
