<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Mehr als die Restschuld laesst sich nicht tilgen.
 *
 * Die Rechnung deckelt es ohnehin — sonst entstuende ein Guthaben bei der
 * Bank, das der Plan nicht kennt. Aber ein gedeckelter Betrag stuende danach
 * im Verlauf und behauptete, es sei so gezahlt worden. Die Absage ist
 * ehrlicher: wer abloesen will, traegt die Restschuld ein.
 */
final class ExtraPaymentExceedsDebt extends RuntimeException
{
    public static function of(): self
    {
        return new self('finance.error.loan_extra_too_large');
    }
}
