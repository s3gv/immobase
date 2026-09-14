<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Aus einem beschlossenen Finanzierungsweg wird ein gefuehrtes Darlehen.
 *
 * Die Gegenrichtung zu {@see LoanDirectory}: dort fragt die Abrechnung die
 * Finanzen, hier meldet sie ihnen etwas. Dieselbe Bauart wie {@see
 * SpecialLevies} bei der Sonderumlage — beschlossen wird im Billing, gefuehrt
 * wird in den Finanzen.
 */
interface DecidedLoans
{
    /**
     * Das beschlossene Darlehen anlegen — **einmal je Massnahme**.
     *
     * Gibt es zu dieser Referenz schon eines, bleibt es, wie es ist. Eine
     * Berichtigung des Beschlusses aendert die Zahlen im Blatt; das gefuehrte
     * Darlehen traegt inzwischen die Bank, den wirklichen Tag der ersten Rate
     * und vielleicht schon eine Sondertilgung. Das zu ueberschreiben hiesse,
     * einen Vertrag aus einer Beschlussvorlage zu korrigieren.
     */
    public function decided(DecidedLoan $loan): void;
}
