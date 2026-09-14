<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Was eine Abrechnung benutzt, verschwindet nicht mehr.
 *
 * Die umgekehrte Richtung: die Finanzen fragen hier, bevor sie eine
 * Kostenposition, einen Jahreswert oder eine Zahlung loeschen. Ohne das
 * bliebe nur der Fremdschluessel — und dessen Meldung liest niemand.
 *
 * Dieselbe Bauart wie `PartyLinkSource` und `TenancyLinkSource`: das haltende
 * Modul meldet sich beim gehaltenen, nicht umgekehrt. Die Finanzen duerfen
 * Billing nicht kennen.
 */
interface StatementSources
{
    /**
     * @param list<string> $sourceIds Kennungen von Jahreswerten oder Zahlungen
     *
     * @return array<string, string> die benutzten, auf die Referenz der Abrechnung
     */
    public function usedByAStatement(array $sourceIds): array;
}
