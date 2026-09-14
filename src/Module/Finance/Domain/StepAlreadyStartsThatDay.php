<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Zu einem Tag gibt es hoechstens eine Stufe.
 *
 * Sonst waere nicht entscheidbar, welche gilt — und die Vorauszahlung eines
 * Monats haette zwei Antworten.
 */
final class StepAlreadyStartsThatDay extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Zu diesem Datum gibt es bereits eine Stufe.');
    }
}
