<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\DunningLevel;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Wie ein Vorgang gerade dasteht.
 *
 * Nicht der Zustand der Forderung, sondern **der der Arbeit**: ist etwas zu
 * tun, und was. Danach fragt jemand, der die Liste oeffnet.
 *
 * „Faellig" heisst: ein Schreiben waere jetzt dran. Entweder ist noch keines
 * hinaus, oder die Frist des letzten ist abgelaufen. Nach der letzten Mahnung
 * ist nichts mehr faellig — dann ist es kein Mahnwesen mehr, sondern das
 * gerichtliche Mahnverfahren.
 */
final readonly class ClaimState
{
    public function __construct(
        public Claim $claim,
        public Money $open,
        public Money $interest,
        public ?DunningLevel $level,
        public ?DateTimeImmutable $payBy,
        public int $daysOverdue,
        /**
         * Wie viele Tage die Frist noch laeuft; negativ heisst abgelaufen.
         *
         * Fertig gerechnet und nicht als Methode: eine Vorlage, die dafuer
         * ein Datum herumreicht, reicht frueher oder spaeter das falsche
         * herum — Twigs `date()` liefert ein veraenderliches.
         */
        public ?int $daysLeft,
        /** Ein Schreiben waere jetzt dran. */
        public bool $isDue,
        /** Die letzte Mahnung ist abgelaufen — es bleibt das Gericht. */
        public bool $needsTheCourt,
    ) {
    }
}
