<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Zufuehrung und Sonderumlage gehoeren zu einer Einheit.
 *
 * Sonst ist nicht nachvollziehbar, wer wie viel eingezahlt hat — und genau
 * das ist die Frage, die bei der naechsten Abrechnung gestellt wird.
 */
final class ReserveNeedsAUnit extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Zu einer Zuführung gehört die Einheit, die eingezahlt hat.');
    }
}
