<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\DistributionKey;

/**
 * Die drei Angaben des ersten Schritts, wenn sie vollstaendig sind.
 *
 * Objekt, Kostenart und Verteilerschluessel gehoeren zusammen: eine Position
 * ohne eines der drei ist keine. Entweder sind alle da — dann gibt es dieses
 * Wertobjekt — oder keines, und der Schritt meldet den Fehler.
 */
final readonly class ChosenAssignment
{
    public function __construct(
        public string $propertyId,
        public CostKind $kind,
        public DistributionKey $key,
    ) {
    }
}
