<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Systemschluessel bleiben, wie sie sind.
 *
 * Flaeche ist ueberall Flaeche, und ein Miteigentumsanteil ist keine Frage
 * der Einstellung. Waeren sie aenderbar, hiesse „nach Flaeche" in zwei
 * Haeusern zweierlei — und die Abrechnung liesse sich nicht mehr vergleichen.
 */
final class DistributionKeyIsASystemKey extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Systemschlüssel lassen sich nicht ändern.');
    }
}
