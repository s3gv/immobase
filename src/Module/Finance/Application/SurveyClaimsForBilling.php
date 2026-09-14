<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\ClaimDirectory;
use App\Module\Finance\Contract\OpenClaim;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Zusammenzaehlen, was die Eigentuemer der Gemeinschaft schulden.
 *
 * Hausgeld **und Sonderumlage**: beide schuldet der Eigentuemer, und eine
 * unbezahlte Sonderumlage ist genauso eine Forderung wie ein offenes
 * Hausgeld. Die Nebenkostenvorauszahlung dagegen steht im Mietvertrag — sie
 * schuldet der Mieter seinem Vermieter.
 *
 * Soll minus Ist, je Einheit, ueber alle Jahre bis zum Stichtag. Mehr steckt
 * nicht dahinter — und mehr steckt auch nicht in den Daten: eine
 * Vorauszahlung weiss, was faellig war und was kam, nicht wann es kam.
 *
 * Wer mehr gezahlt hat als faellig war, erscheint nicht. Ein Guthaben ist
 * keine Forderung der Gemeinschaft, und es hier mit einem Rueckstand einer
 * anderen Einheit zu verrechnen, machte aus zwei Vorgaengen einen.
 */
final readonly class SurveyClaimsForBilling implements ClaimDirectory
{
    public function __construct(private AdvancePaymentRepository $payments)
    {
    }

    public function openAdvances(array $unitIds, DateTimeImmutable $upTo): array
    {
        $found = [];

        foreach ($this->payments->dueUntil($unitIds, $upTo) as $payment) {
            if (!$payment->kind()->isOwedByTheOwner()) {
                continue;
            }

            $found[$payment->unitId()] = self::plus($found[$payment->unitId()] ?? null, $payment);
        }

        $open = array_values(array_filter(
            $found,
            static fn (OpenClaim $claim): bool => !$claim->open->isZero() && !$claim->open->isNegative(),
        ));

        usort($open, static fn (OpenClaim $one, OpenClaim $other): int => $other->open->cents() <=> $one->open->cents());

        return $open;
    }

    /** Eine weitere faellige Zahlung derselben Einheit. */
    private static function plus(?OpenClaim $soFar, AdvancePayment $payment): OpenClaim
    {
        $expected = ($soFar->expected ?? Money::zero())->plus($payment->expected());
        $received = ($soFar->received ?? Money::zero())->plus($payment->received());
        $missing = $payment->expected()->minus($payment->received());

        return new OpenClaim(
            $payment->unitId(),
            $expected,
            $received,
            $expected->minus($received),
            self::earlier($soFar, $payment, $missing),
        );
    }

    /**
     * Das aelteste Jahr, in dem etwas offen blieb.
     *
     * Ein bezahltes Jahr zaehlt nicht mit: sonst stuende bei jemandem, der
     * seit 2020 dabei ist und einmal im Dezember zu wenig ueberwiesen hat,
     * „seit 2020 offen".
     *
     * Null heisst „bisher nichts offen". Bei einer Forderung, die uebrig
     * bleibt, kann das nicht stehenbleiben: ihre Summe ist positiv, also gab
     * es mindestens eine Zahlung, die zu klein war.
     */
    private static function earlier(?OpenClaim $soFar, AdvancePayment $payment, Money $missing): int
    {
        $known = $soFar->since ?? 0;

        if ($missing->isZero() || $missing->isNegative()) {
            return $known;
        }

        return 0 === $known ? $payment->fiscalYear() : min($known, $payment->fiscalYear());
    }
}
