<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;

/**
 * Der Stand der Erhaltungsruecklage an einem Stichtag, mit dem Weg dorthin.
 *
 * § 28 Abs. 4 WEG verlangt den **Ist-Stand**, und nur ihn: der Soll-Stand
 * waere genau dann hoeher, wenn jemand nicht gezahlt hat — und dieser
 * Fehlbetrag steht schon als Forderung in der Aufstellung. Zweimal dieselbe
 * Luecke liest sich wie doppelter Schaden.
 *
 * Was stattdessen danebensteht, ist die Entwicklung im Berichtsjahr. Sie
 * beantwortet dieselbe Frage — wo ist das Geld hin — und behauptet dabei
 * nichts, was nicht gebucht ist.
 *
 * Der Anfangsbestand ist keine eigene Buchung, sondern der Schlussbestand des
 * Vortages. Eine zweite Quelle dafuer liefe frueher oder spaeter neben der
 * ersten her.
 *
 * Alle Betraege sind Wirkungen und keine Vorzeichenlosen: Entnahmen sind
 * negativ, Zinsen duerfen es sein. Damit gilt
 * `closing = opening + contributions + specialLevies + interest + withdrawals`
 * als gerade Addition — und wer die Spalte nachrechnet, kommt heraus, wo der
 * Bericht herauskommt.
 */
final readonly class ReserveStanding
{
    public function __construct(
        public Money $opening,
        public Money $contributions,
        public Money $specialLevies,
        public Money $interest,
        /** Negativ — sie mindert den Bestand. */
        public Money $withdrawals,
        public Money $closing,
    ) {
    }

    public static function nothing(): self
    {
        return new self(
            Money::zero(),
            Money::zero(),
            Money::zero(),
            Money::zero(),
            Money::zero(),
            Money::zero(),
        );
    }

    /** Hat sich im Berichtsjahr ueberhaupt etwas bewegt? */
    public function stoodStill(): bool
    {
        return $this->contributions->isZero()
            && $this->specialLevies->isZero()
            && $this->interest->isZero()
            && $this->withdrawals->isZero();
    }
}
