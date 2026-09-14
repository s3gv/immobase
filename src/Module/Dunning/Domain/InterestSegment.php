<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Ein Abschnitt der Zinsstaffel — eine Zeile auf dem Blatt.
 *
 * Betrag, Satz und Tage stehen einzeln da und nicht nur ihr Ergebnis: wer
 * eine Zinsforderung bestreitet, bestreitet einen dieser vier Werte, und eine
 * Zahl ohne ihre Bestandteile laesst sich nicht bestreiten, sondern nur
 * glauben.
 */
final readonly class InterestSegment
{
    public function __construct(
        /** Erster Tag des Abschnitts, einschliesslich. */
        public DateTimeImmutable $from,
        /** Letzter Tag des Abschnitts, einschliesslich. */
        public DateTimeImmutable $until,
        public int $days,
        public Money $amount,
        public int $baseRateBps,
        public int $pointsBps,
        public Money $interest,
    ) {
    }

    /** Der angewandte Satz: Basiszins plus Punkte. */
    public function rateBps(): int
    {
        return $this->baseRateBps + $this->pointsBps;
    }
}
