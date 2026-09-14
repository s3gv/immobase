<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Mitgelieferte Kostenarten bleiben.
 *
 * Sie sind die gemeinsame Sprache, in der jede Abrechnung gruppiert — und
 * wer eine davon loeschte, haette Positionen, die auf nichts mehr zeigen.
 * Was nicht gebraucht wird, stoert auch nicht: es steht in einer Liste, die
 * man einmal im Jahr aufmacht.
 */
final class CostKindIsPartOfTheCatalogue extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Mitgelieferte Kostenarten lassen sich nicht löschen.');
    }
}
