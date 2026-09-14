<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimIsSettled;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Das Geld ist gekommen — oder ein Teil davon.
 *
 * **Der Tag ist das Wesentliche.** Eine Vorauszahlung kennt bewusst kein
 * Zahlungsdatum; ohne einen Tag liesse sich nicht belegen, bis wann die
 * Zinsen liefen. Vorbelegt ist der Tag des Klicks, korrigierbar — mehr weiss
 * niemand, und mehr zu behaupten waere schlimmer.
 *
 * Der Zinsanspruch ueberlebt die Hauptforderung: wer drei Monate zu spaet
 * zahlt, schuldet die Zinsen weiterhin. Die Anwendung zeigt sie weiter an und
 * verfolgt sie nicht von selbst — ob wegen neun Euro ein zweites Schreiben
 * hinausgeht, ist eine Entscheidung.
 */
final readonly class SettleClaim
{
    public function __construct(private ClaimRepository $claims)
    {
    }

    /**
     * @throws ClaimIsSettled
     */
    public function settle(Claim $claim, DateTimeImmutable $on): void
    {
        $claim->settle($on);
        $this->claims->save($claim);
    }

    /** Eine Teilzahlung mindert den offenen Betrag ab dem Tag ihrer Erfassung. */
    public function reduce(Claim $claim, Money $open, DateTimeImmutable $on): void
    {
        if ($open->isZero() || $open->isNegative()) {
            $this->settle($claim, $on);

            return;
        }

        $claim->nowOpen($open, $on);
        $this->claims->save($claim);
    }
}
