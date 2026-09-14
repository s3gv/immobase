<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Bank;

use InvalidArgumentException;

/**
 * Das ist keine IBAN.
 *
 * Entweder stimmt die Gestalt nicht oder die Pruefziffer — meist ein
 * Zahlendreher. Welches von beidem, sagt die Meldung bewusst nicht: fuer
 * den, der sie liest, ist es dasselbe Nachsehen auf dem Kontoauszug.
 */
final class NotAnIban extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Das ist keine gültige IBAN.');
    }
}
