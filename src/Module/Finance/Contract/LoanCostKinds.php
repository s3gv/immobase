<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Die beiden mitgelieferten Kostenarten des Darlehens.
 *
 * Zins und Tilgung getrennt: der Zins ist Aufwand, die Tilgung schichtet
 * Vermoegen um. Eine gemeinsame Zeile verschwiege das, und beschlossen wuerde
 * ueber eine Zahl, die zwei Dinge zugleich ist.
 *
 * Fehlt eine — jemand hat sie in der Datenbank entfernt —, steht sie als
 * `null` da und die Zeile bleibt ohne Kostenart stehen. Dieselbe Haltung wie
 * beim Verteilerschluessel: besser eine sichtbare Luecke als eine Kostenart,
 * die niemand gewaehlt hat.
 */
final readonly class LoanCostKinds
{
    public function __construct(
        public ?CostKindBrief $interest,
        public ?CostKindBrief $principal,
    ) {
    }
}
