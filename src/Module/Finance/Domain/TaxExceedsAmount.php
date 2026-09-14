<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use InvalidArgumentException;

/**
 * In einem Betrag steckt mehr Umsatzsteuer, als er hat.
 *
 * Abgelehnt und nicht gekappt: in der Abrechnung wuerde der Nettoanteil
 * sonst auf null gesetzt, und die Kosten verschwaenden still.
 */
final class TaxExceedsAmount extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Die enthaltene Umsatzsteuer kann nicht größer sein als der Betrag.');
    }
}
