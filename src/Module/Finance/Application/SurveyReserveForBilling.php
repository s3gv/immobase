<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\ReserveDirectory;
use App\Module\Finance\Contract\ReserveStanding;
use App\Module\Finance\Domain\ReserveBalance;
use App\Module\Finance\Domain\ReserveFilter;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Module\Finance\Domain\ReserveMovementRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Ruecklagenfragen der Abrechnungen beantworten.
 *
 * Zwei Fragen, zwei Fragesteller: der Wirtschaftsplan will wissen, was heute
 * da ist, der Vermoegensbericht, was am Stichtag da war.
 *
 * Gerechnet wird nicht hier, sondern in {@see ReserveBalance}
 * — dieselbe Rechnung wie auf der Ruecklagenseite. Zwei Summen ueber dieselben
 * Bewegungen liefen frueher oder spaeter auseinander.
 */
final readonly class SurveyReserveForBilling implements ReserveDirectory
{
    public function __construct(private ReserveMovementRepository $movements)
    {
    }

    public function balanceOf(string $propertyId): Money
    {
        $balance = $this->movements->forProperties([$propertyId])[$propertyId] ?? null;

        return $balance?->total() ?? Money::zero();
    }

    public function standingAt(
        string $propertyId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ReserveStanding {
        $balance = $this->movements->forProperties([$propertyId])[$propertyId] ?? null;

        if (null === $balance) {
            return ReserveStanding::nothing();
        }

        $period = $balance->within($from, $to);

        return new ReserveStanding(
            self::openingOf($balance, $period, $from),
            self::sumOf($period, ReserveMovementKind::Contribution),
            self::sumOf($period, ReserveMovementKind::SpecialLevy),
            self::sumOf($period, ReserveMovementKind::Interest),
            self::sumOf($period, ReserveMovementKind::Withdrawal),
            $balance->until($to)->total(),
        );
    }

    public function contributionsIn(string $propertyId, int $year): Money
    {
        $balance = $this->movements->forProperties([$propertyId])[$propertyId] ?? null;

        if (null === $balance) {
            return Money::zero();
        }

        return $balance
            ->only(ReserveFilter::of(ReserveMovementKind::Contribution->value, $year, ''))
            ->total();
    }

    /**
     * Der Anfangsbestand: was vor dem Zeitraum da war — und der erfasste dazu.
     *
     * Ein **Anfangsbestand** ist keine Bewegung des Jahres, sondern die
     * Auskunft, was schon dalag, bevor gezaehlt wurde. Gebucht wird er
     * meistens auf den ersten Tag des ersten Jahres, und damit faellt er in
     * den Zeitraum des ersten Berichts. Zaehlte man ihn dort zu den
     * Zufuehrungen, stuende im Bericht, die Eigentuemer haetten vierzigtausend
     * Euro eingezahlt; liesse man ihn ganz weg, ginge die Spalte nicht mehr
     * auf den Schlussbestand auf.
     */
    private static function openingOf(
        ReserveBalance $balance,
        ReserveBalance $period,
        DateTimeImmutable $from,
    ): Money {
        return $balance->until($from->modify('-1 day'))->total()
            ->plus(self::sumOf($period, ReserveMovementKind::Opening));
    }

    /** Die Wirkung einer Art auf den Bestand — Entnahmen mindern ihn und stehen darum negativ da. */
    private static function sumOf(ReserveBalance $balance, ReserveMovementKind $kind): Money
    {
        return $balance->only(ReserveFilter::of($kind->value, null, ''))->total();
    }
}
