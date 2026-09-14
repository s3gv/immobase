<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Der Stand einer Erhaltungsruecklage.
 *
 * Kein Datensatz, sondern eine Rechnung: Anfangsbestand + Zufuehrungen +
 * Sonderumlagen + Zinsen − Entnahmen. Ein mitgefuehrter Saldo waere eine
 * zweite Wahrheit neben den Bewegungen, und zwei Wahrheiten laufen
 * auseinander.
 */
final readonly class ReserveBalance
{
    /**
     * @param list<ReserveMovement> $movements das juengste zuerst
     */
    private function __construct(private array $movements)
    {
    }

    /**
     * @param list<ReserveMovement> $movements
     */
    public static function of(array $movements): self
    {
        usort(
            $movements,
            static fn (ReserveMovement $one, ReserveMovement $other): int => $other->occurredOn() <=> $one->occurredOn(),
        );

        return new self($movements);
    }

    /** @return list<ReserveMovement> */
    public function movements(): array
    {
        return $this->movements;
    }

    public function isEmpty(): bool
    {
        return [] === $this->movements;
    }

    /**
     * Derselbe Stand, auf eine Auswahl eingeschraenkt.
     *
     * Wieder ein Stand und keine blosse Liste: dann ist die Summe der
     * Auswahl dieselbe Rechnung wie die des Ganzen und nicht eine zweite,
     * die anders zaehlt.
     */
    public function only(ReserveFilter $filter): self
    {
        if ($filter->isEmpty()) {
            return $this;
        }

        return new self(array_values(array_filter(
            $this->movements,
            static fn (ReserveMovement $movement): bool => $filter->matches($movement),
        )));
    }

    /**
     * Derselbe Stand, wie er an einem Tag war.
     *
     * Der Vermoegensbericht spricht ueber einen Stichtag und nicht ueber
     * heute. Wer den heutigen Bestand in einen Bericht ueber das vergangene
     * Jahr schriebe, haette ein Schreiben, das sich mit der naechsten Buchung
     * aendert — und ein zugestelltes Schreiben aendert sich nicht.
     *
     * Ein Storno wirkt dabei rueckwirkend: eine Bewegung, die es nie gab,
     * stand auch am Stichtag nicht auf dem Konto. Der Tag, an dem storniert
     * wurde, steht auf der Bewegung und nicht im Bestand.
     */
    public function until(DateTimeImmutable $day): self
    {
        return new self(array_values(array_filter(
            $this->movements,
            static fn (ReserveMovement $movement): bool => $movement->occurredOn() <= $day,
        )));
    }

    /** Derselbe Stand, auf einen Zeitraum eingeschraenkt — beide Tage zaehlen mit. */
    public function within(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self(array_values(array_filter(
            $this->until($to)->movements,
            static fn (ReserveMovement $movement): bool => $movement->occurredOn() >= $from,
        )));
    }

    /**
     * Die Jahre, in denen etwas gebucht wurde — das juengste zuerst.
     *
     * Fuer den Filter: zur Wahl steht, was es auch wirklich gibt.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $years = [];

        foreach ($this->movements as $movement) {
            $years[(int) $movement->occurredOn()->format('Y')] = true;
        }

        $found = array_keys($years);
        rsort($found);

        return $found;
    }

    public function total(): Money
    {
        $sum = Money::zero();

        foreach ($this->movements as $movement) {
            $sum = $sum->plus($movement->effect());
        }

        return $sum;
    }

    /**
     * Ein Anfangsbestand, der noch gilt.
     *
     * Ein stornierter zaehlt nicht: sonst liesse sich eine Fehleingabe zwar
     * zuruecknehmen, aber nie durch die richtige ersetzen.
     */
    public function hasAnOpeningBalance(): bool
    {
        foreach ($this->movements as $movement) {
            if (ReserveMovementKind::Opening === $movement->kind() && !$movement->isReversed()) {
                return true;
            }
        }

        return false;
    }
}
