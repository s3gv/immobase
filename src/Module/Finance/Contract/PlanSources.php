<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Was ein Wirtschaftsplan benutzt, verschwindet nicht mehr.
 *
 * Dieselbe Bauart und derselbe Grund wie bei {@see StatementSources}: die
 * Finanzen fragen hier, bevor sie eine Kostenart oder einen eigenen
 * Verteilerschluessel loeschen. Das haltende Modul meldet sich beim
 * gehaltenen, nicht umgekehrt.
 */
interface PlanSources
{
    /**
     * @param list<string> $sourceIds Kennungen von Kostenarten oder Verteilerschluesseln
     *
     * @return array<string, string> die benutzten, auf die Bezeichnung des Plans
     */
    public function usedByAPlan(array $sourceIds): array;
}
