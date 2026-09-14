<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DomainException;

/**
 * Aus einem Entwurf entsteht kein PDF.
 *
 * Ein Entwurf hat kein Briefdatum, und ein Brief ohne Datum ist keiner. Wer
 * ihn sehen will, sieht die Vorschau.
 */
final class StatementIsNotReleased extends DomainException
{
    public function __construct()
    {
        parent::__construct('Aus einem Entwurf entsteht kein PDF — geben Sie ihn erst frei.');
    }
}
