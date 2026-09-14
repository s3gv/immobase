<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use DateTimeImmutable;

/**
 * Was der Gemeinschaft von ihren Mitgliedern noch zusteht.
 *
 * Eine Forderung der Gemeinschaft gegen ihre Mitglieder, und darum gehoert
 * sie in die Aufstellung des Gemeinschaftsvermoegens nach § 28 Abs. 4 WEG.
 *
 * **Hausgeld und Sonderumlage.** Beide schuldet der Eigentuemer der
 * Gemeinschaft; eine unbezahlte Sonderumlage ist genauso eine Forderung wie
 * ein offenes Hausgeld. Die Nebenkostenvorauszahlung dagegen steht im
 * Mietvertrag: sie schuldet der Mieter seinem Vermieter, und in einer
 * Aufstellung des Gemeinschaftsvermoegens waere sie fremdes Geld.
 *
 * **Ueber Jahresgrenzen hinweg.** Ein Rueckstand aus dem Vorjahr ist am
 * Stichtag immer noch eine Forderung — ein Bericht, der nur das Berichtsjahr
 * saehe, wuerde die Gemeinschaft aermer rechnen, als sie ist.
 */
interface ClaimDirectory
{
    /**
     * @param list<string> $unitIds
     *
     * @return list<OpenClaim> nur Einheiten mit einem offenen Betrag, nach Hoehe sortiert
     */
    public function openAdvances(array $unitIds, DateTimeImmutable $upTo): array;
}
