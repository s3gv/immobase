<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Der Stand der Erhaltungsruecklage, fuer die Planung.
 *
 * Ueber die Zufuehrung zur Ruecklage wird nach § 28 Abs. 1 WEG eigens
 * beschlossen — der Wirtschaftsplan schlaegt sie vor und braucht dafuer zwei
 * Zahlen: was zuletzt zugefuehrt wurde und was inzwischen da ist. Ohne die
 * zweite ist die erste eine Zahl ohne Massstab.
 *
 * Der Vermoegensbericht fragt dieselbe Ruecklage etwas anderes: nicht was
 * heute da ist, sondern was am Stichtag da war.
 */
interface ReserveDirectory
{
    /** Was auf der Ruecklage steht — Anfangsbestand, Zufuehrungen, Zinsen, minus Entnahmen. */
    public function balanceOf(string $propertyId): Money;

    /**
     * Derselbe Stand, aber an einem Stichtag und mit der Entwicklung dorthin.
     *
     * Der Vermoegensbericht spricht ueber ein abgelaufenes Jahr. {@see
     * balanceOf()} liefert den heutigen Bestand — in einem Bericht ueber das
     * vergangene Jahr waere das eine Zahl, die sich mit der naechsten Buchung
     * aendert.
     */
    public function standingAt(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): ReserveStanding;

    /** Was in diesem Jahr zugefuehrt wurde. */
    public function contributionsIn(string $propertyId, int $year): Money;
}
